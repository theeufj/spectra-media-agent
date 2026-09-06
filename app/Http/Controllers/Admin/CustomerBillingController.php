<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
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
     */
    private function unreconciledSpend(Customer $customer, AdSpendCredit $credit): float
    {
        $campaignIds = $customer->campaigns()->pluck('id');

        $googleSpend = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $facebookSpend = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');

        $totalActualSpend = round((float) $googleSpend + (float) $facebookSpend, 2);
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

        $transactions = $credit->transactions()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($tx) use ($campaignIds) {
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
                    $billingDate = $tx->created_at->subDay()->toDateString();

                    $google = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
                        ->when($isDeduction, fn ($q) => $q->whereDate('date', $billingDate))
                        ->sum('cost');

                    $facebook = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
                        ->when($isDeduction, fn ($q) => $q->whereDate('date', $billingDate))
                        ->sum('cost');

                    $row['platform_breakdown'] = [
                        ['platform' => 'Google Ads',   'spend' => round((float) $google, 2)],
                        ['platform' => 'Facebook Ads', 'spend' => round((float) $facebook, 2)],
                    ];
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
        ]);
    }
}
