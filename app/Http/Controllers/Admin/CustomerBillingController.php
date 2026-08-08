<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
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

        $campaignIds = $customer->campaigns()->pluck('id');
        $googleSpend = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $facebookSpend = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $totalActualSpend = round($googleSpend + $facebookSpend, 2);
        $totalDebited = round($credit->transactions()->where('type', AdSpendTransaction::TYPE_DEDUCTION)->sum('amount'), 2);
        $unreconciled = round($totalActualSpend - $totalDebited, 2);

        if ($unreconciled <= 0) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Credit is already reconciled — no unreconciled spend found.']);
        }

        $newBalance = round(max(0, $credit->current_balance - $unreconciled), 2);

        $credit->transactions()->create([
            'type' => AdSpendTransaction::TYPE_ADJUSTMENT,
            'amount' => $unreconciled,
            'balance_after' => $newBalance,
            'description' => 'Admin reconciliation — $'.number_format($unreconciled, 2).' untracked spend applied (Google: $'.number_format($googleSpend, 2).', Facebook: $'.number_format($facebookSpend, 2).')',
        ]);

        $credit->update(['current_balance' => $newBalance]);

        Log::info('Admin: Ad spend reconciled for customer', [
            'customer_id' => $customer->id,
            'unreconciled' => $unreconciled,
            'new_balance' => $newBalance,
            'admin_id' => auth()->id(),
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => '$'.number_format($unreconciled, 2).' reconciled. New balance: $'.number_format($newBalance, 2).'.']);
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

                if (in_array($tx->type, [AdSpendTransaction::TYPE_DEDUCTION, AdSpendTransaction::TYPE_ADJUSTMENT])) {
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

        $totalDebits = $credit->transactions()
            ->whereIn('type', [AdSpendTransaction::TYPE_DEDUCTION, AdSpendTransaction::TYPE_ADJUSTMENT])
            ->sum('amount');

        return Inertia::render('Admin/CustomerCreditLedger', [
            'customer' => $customer->only('id', 'name', 'business_name'),
            'credit' => [
                'current_balance' => (float) $credit->current_balance,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'total_credits' => round((float) $totalCredits, 2),
                'total_debits' => round((float) $totalDebits, 2),
            ],
            'transactions' => $transactions,
        ]);
    }
}
