<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdSpendBillingController extends Controller
{
    protected AdSpendBillingService $billingService;

    public function __construct(AdSpendBillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Show the ad spend billing dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer) {
            return Inertia::render('Billing/AdSpend', [
                'credit' => null,
                'transactions' => [],
                'error' => 'No customer account found',
            ]);
        }

        $credit = $customer->adSpendCredit;

        return Inertia::render('Billing/AdSpend', [
            'credit' => $credit ? [
                'id' => $credit->id,
                'initial_credit_amount' => $credit->initial_credit_amount,
                'current_balance' => $credit->current_balance,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'last_successful_charge_at' => $credit->last_successful_charge_at,
                'failed_charge_count' => $credit->failed_charge_count,
                'grace_period_ends_at' => $credit->grace_period_ends_at,
                'campaigns_paused_at' => $credit->campaigns_paused_at,
                'average_daily_spend' => $credit->getAverageDailySpend(),
                'budget_multiplier' => $credit->getBudgetMultiplier(),
                'can_run_campaigns' => $credit->canRunCampaigns(),
            ] : null,
            'transactions' => $credit ? $credit->transactions()
                ->latest()
                ->take(50)
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'description' => $t->description,
                    'created_at' => $t->created_at,
                ]) : [],
        ]);
    }

    /**
     * Get credit balance and status (API).
     */
    public function getBalance(Request $request)
    {
        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer || !$customer->adSpendCredit) {
            return response()->json([
                'success' => false,
                'error' => 'No ad spend credit account found',
            ], 404);
        }

        $credit = $customer->adSpendCredit;

        return response()->json([
            'success' => true,
            'balance' => $credit->current_balance,
            'status' => $credit->status,
            'payment_status' => $credit->payment_status,
            'can_run_campaigns' => $credit->canRunCampaigns(),
            'budget_multiplier' => $credit->getBudgetMultiplier(),
            'average_daily_spend' => $credit->getAverageDailySpend(),
            'days_remaining' => $credit->getAverageDailySpend() > 0 
                ? $credit->current_balance / $credit->getAverageDailySpend() 
                : null,
        ]);
    }

    /**
     * Get transaction history (API).
     */
    public function getTransactions(Request $request)
    {
        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer || !$customer->adSpendCredit) {
            return response()->json([
                'success' => false,
                'error' => 'No ad spend credit account found',
            ], 404);
        }

        $transactions = $customer->adSpendCredit->transactions()
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Add credit to account (manual top-up).
     */
    public function addCredit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:50|max:10000',
        ]);

        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'error' => 'No customer account found',
            ], 404);
        }

        $result = $this->billingService->addCredit(
            $customer,
            $request->amount,
            'Manual top-up via dashboard'
        );

        if ($result['success']) {
            Log::info('AdSpendBilling: Manual credit added', [
                'customer_id' => $customer->id,
                'amount' => $request->amount,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'new_balance' => $result['new_balance'],
                'message' => 'Credit added successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to add credit',
        ], 400);
    }

    /**
     * Retry payment after failure.
     */
    public function retryPayment(Request $request)
    {
        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer || !$customer->adSpendCredit) {
            return response()->json([
                'success' => false,
                'error' => 'No ad spend credit account found',
            ], 404);
        }

        $credit = $customer->adSpendCredit;

        // Calculate amount needed to restore account
        $avgDailySpend = $credit->getAverageDailySpend();
        $replenishAmount = max(50, $avgDailySpend * 7); // At least $50, or 7 days of spend

        $result = $this->billingService->addCredit(
            $customer,
            $replenishAmount,
            'Payment recovery'
        );

        if ($result['success']) {
            // Restore the account status
            $credit->restoreAccount();

            Log::info('AdSpendBilling: Payment recovered via retry', [
                'customer_id' => $customer->id,
                'amount' => $replenishAmount,
            ]);

            return response()->json([
                'success' => true,
                'new_balance' => $result['new_balance'],
                'message' => 'Payment successful! Your campaigns will resume shortly.',
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Payment failed',
        ], 400);
    }
}
