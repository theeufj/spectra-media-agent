<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Admin view of a customer's ad-spend credit ledger, and manual spend reconciliation.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class CustomerBillingController extends Controller
{
    /**
     * How many ledger rows the credit page renders.
     *
     * A daily deduction plus the occasional top-up means the ledger grows a
     * row a day for the life of the account, and this page fetched all of them.
     */
    private const LEDGER_ROWS = 500;

    public function reconcileSpend(Customer $customer)
    {
        $credit = $customer->adSpendCredit;

        if (! $credit) {
            return back()->with('flash', ['type' => 'error', 'message' => 'No ad spend credit account found for this customer.']);
        }

        $unreconciled = $this->unreconciledSpend($customer, $credit);

        if ($unreconciled <= 0) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Credit is already reconciled — no unreconciled spend found.']);
        }

        // Balance mutation + ledger row in one transaction, on a locked row.
        //
        // This read the balance, wrote the ledger row, and then wrote the
        // balance — three statements, no transaction, no lock. A nightly
        // deduction landing in between was silently overwritten, because the
        // new balance was computed from a value read before it.
        $result = DB::transaction(function () use ($customer, $credit) {
            $locked = AdSpendCredit::whereKey($credit->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            // Recompute under the lock: another path may have settled some or
            // all of this while we were waiting for the row.
            $owing = $this->unreconciledSpend($customer, $locked);

            if ($owing <= 0) {
                return null;
            }

            // Negative, like every other debit. A positive adjustment left the
            // account's debit total short by twice the adjustment, so the same
            // spend stayed permanently "unreconciled" and could be charged again.
            $locked->recordAdjustment(
                -$owing,
                'Admin reconciliation — $'.number_format($owing, 2).' untracked spend applied'
            );

            return $owing;
        });

        if ($result === null) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Credit is already reconciled — no unreconciled spend found.']);
        }

        Log::info('Admin: Ad spend reconciled for customer', [
            'customer_id' => $customer->id,
            'unreconciled' => $result,
            'new_balance' => $credit->fresh()->current_balance,
            'admin_id' => auth()->id(),
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'message' => '$'.number_format($result, 2).' reconciled. New balance: $'.number_format((float) $credit->fresh()->current_balance, 2).'.',
        ]);
    }

    /**
     * Spend recorded on the platforms that has not been taken out of the account yet.
     *
     * Deductions are stored negative and the legacy 'debit' rows positive, so
     * the debited total has to come from AdSpendTransaction::totalDebited()
     * rather than a raw SUM(). Subtracting a negative here is what turned
     * $723.70 of genuinely unbilled spend into a $3,369.90 charge.
     *
     * All four platforms, from the one shared list. This summed Google and
     * Facebook only, against a debit total that is account-wide and a nightly
     * deduction that bills all four — so for a Microsoft or LinkedIn customer
     * the figure went negative and the button reported "already reconciled"
     * while genuinely unbilled Google spend sat there.
     */
    private function unreconciledSpend(Customer $customer, AdSpendCredit $credit): float
    {
        $campaignIds = $customer->campaigns()->pluck('id');

        $totalActualSpend = 0.0;

        foreach (AdSpendCredit::PLATFORM_SPEND_MODELS as $model) {
            $totalActualSpend += (float) $model::whereIn('campaign_id', $campaignIds)->sum('cost');
        }

        $totalActualSpend = round($totalActualSpend, 2);
        $totalDebited = AdSpendTransaction::totalDebited($credit->getKey());

        return round($totalActualSpend - $totalDebited, 2);
    }

    public function customerCreditLedger(Customer $customer)
    {
        $credit = $customer->adSpendCredit;

        if (! $credit) {
            return redirect()->route('admin.customers.show', $customer)->with('flash', [
                'type' => 'error', 'message' => 'No ad spend credit account found for this customer.',
            ]);
        }

        $campaignIds = $customer->campaigns()->pluck('id');

        // The most recent window rather than the whole account. This page had
        // no bound at all, so it grew a row a day forever.
        $transactionsTotal = $credit->transactions()->count();

        $ledger = $credit->transactions()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::LEDGER_ROWS)
            ->get()
            ->reverse()
            ->values();

        [$dailySpend, $allTimeSpend] = $this->platformSpendForLedger($ledger, $campaignIds);

        $transactions = $ledger->map(function ($tx) use ($dailySpend, $allTimeSpend) {
            $row = [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'balance_after' => (float) $tx->balance_after,
                'description' => $tx->description,
                'stripe_charge_id' => $tx->stripe_charge_id,
                'created_at' => $tx->created_at->toIso8601String(),
                'platform_breakdown' => null,
            ];

            if (in_array($tx->type, AdSpendTransaction::DEBIT_TYPES, true)) {
                // Deductions = daily billing for the previous day; adjustments = lump-sum reconciliation.
                // For deductions, scope to the specific date billed; for adjustments, show all-time totals.
                $isDeduction = $tx->type === AdSpendTransaction::TYPE_DEDUCTION;
                $billingDate = $tx->created_at->copy()->subDay()->toDateString();

                // Every platform the deduction actually billed for. Showing
                // Google and Facebook alone left a Microsoft or LinkedIn
                // customer's row reading $0.00 against a real charge.
                $row['platform_breakdown'] = [];

                foreach (AdSpendCredit::PLATFORM_SPEND_MODELS as $label => $model) {
                    $spend = $isDeduction
                        ? ($dailySpend[$label][$billingDate] ?? 0.0)
                        : ($allTimeSpend[$label] ?? 0.0);

                    $row['platform_breakdown'][] = [
                        'platform' => $label,
                        'spend' => round($spend, 2),
                    ];
                }
            }

            return $row;
        });

        $totalCredits = $credit->transactions()
            ->whereIn('type', [AdSpendTransaction::TYPE_CREDIT, AdSpendTransaction::TYPE_REFUND])
            ->sum('amount');

        $totalDebits = AdSpendTransaction::totalDebited($credit->getKey());

        return Inertia::render('Admin/CustomerCreditLedger', [
            'customer' => $customer->only('id', 'name', 'business_name'),
            'credit' => [
                'current_balance' => (float) $credit->current_balance,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'total_credits' => round((float) $totalCredits, 2),
                'total_debits' => $totalDebits,
            ],
            'transactions' => $transactions,
            'transactionsTotal' => $transactionsTotal,
            'transactionsShown' => $transactions->count(),
        ]);
    }

    /**
     * Per-platform spend for a page of ledger rows, in two queries per platform.
     *
     * The breakdown used to be built inside the row map: a SUM per platform per
     * debit row, so a year-old account issued well over a thousand aggregates
     * to draw one table. The two shapes it needs are both fixed per page —
     * deductions want the day they billed for, and adjustment/legacy-debit rows
     * all want the same account-wide total — so each is fetched once here.
     *
     * @param  \Illuminate\Support\Collection<int, AdSpendTransaction>  $ledger
     * @return array{0: array<string, array<string, float>>, 1: array<string, float>}
     */
    private function platformSpendForLedger($ledger, $campaignIds): array
    {
        $debits = $ledger->filter(
            fn ($tx) => in_array($tx->type, AdSpendTransaction::DEBIT_TYPES, true)
        );

        $billingDates = $debits
            ->filter(fn ($tx) => $tx->type === AdSpendTransaction::TYPE_DEDUCTION)
            ->map(fn ($tx) => $tx->created_at->copy()->subDay()->toDateString())
            ->unique()
            ->values()
            ->all();

        $needsAllTime = $debits->contains(fn ($tx) => $tx->type !== AdSpendTransaction::TYPE_DEDUCTION);

        $dailySpend = [];
        $allTimeSpend = [];

        foreach (AdSpendCredit::PLATFORM_SPEND_MODELS as $label => $model) {
            if ($billingDates !== []) {
                $dailySpend[$label] = $model::whereIn('campaign_id', $campaignIds)
                    ->whereIn('date', $billingDates)
                    ->selectRaw('date, SUM(cost) as spend')
                    ->groupBy('date')
                    ->get()
                    ->mapWithKeys(fn ($row) => [
                        $row->date->toDateString() => (float) $row->getAttribute('spend'),
                    ])
                    ->all();
            }

            if ($needsAllTime) {
                $allTimeSpend[$label] = (float) $model::whereIn('campaign_id', $campaignIds)->sum('cost');
            }
        }

        return [$dailySpend, $allTimeSpend];
    }
}
