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
                'paymentFailed' => false,
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
                'daily_budget' => $credit->daily_budget ?? 0,
                'estimated_daily_spend' => $credit->estimated_daily_spend ?? 0,
                'last_successful_charge_at' => $credit->last_successful_charge_at,
                'failed_charge_count' => $credit->failed_charge_count,
                'failed_payments_count' => $credit->failed_charge_count,
                'grace_period_ends_at' => $credit->grace_period_ends_at,
                'campaigns_paused_at' => $credit->campaigns_paused_at,
                'average_daily_spend' => $credit->getAverageDailySpend(),
                'days_remaining' => $credit->getAverageDailySpend() > 0 
                    ? round($credit->current_balance / $credit->getAverageDailySpend(), 1)
                    : null,
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
            'paymentFailed' => $credit && $credit->payment_status === 'failed',
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

    /**
     * Update the customer's payment method (Stripe).
     */
    public function updatePaymentMethod(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
            'retry_payment' => 'boolean',
        ]);

        $user = $request->user();

        try {
            // Update the default payment method using Cashier
            $user->updateDefaultPaymentMethod($request->payment_method_id);

            Log::info('AdSpendBilling: Payment method updated', [
                'user_id' => $user->id,
            ]);

            // If retry payment flag is set, also retry the failed payment
            if ($request->retry_payment) {
                $customer = $user->customers()->first();
                
                if ($customer && $customer->adSpendCredit) {
                    $credit = $customer->adSpendCredit;
                    $avgDailySpend = $credit->getAverageDailySpend();
                    $replenishAmount = max(50, $avgDailySpend * 7);

                    $result = $this->billingService->addCredit(
                        $customer,
                        $replenishAmount,
                        'Payment recovery after payment method update'
                    );

                    if ($result['success']) {
                        $credit->restoreAccount();

                        return response()->json([
                            'success' => true,
                            'message' => 'Payment method updated and payment successful! Your campaigns will resume shortly.',
                            'new_balance' => $result['new_balance'],
                        ]);
                    } else {
                        return response()->json([
                            'success' => false,
                            'error' => 'Payment method updated but payment still failed: ' . ($result['error'] ?? 'Unknown error'),
                        ], 400);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('AdSpendBilling: Failed to update payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to update payment method: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Set up ad spend billing during deployment (first time setup).
     * This creates the credit account and charges the initial 7-day credit.
     */
    public function setupForDeployment(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
            'daily_budget' => 'required|numeric|min:1',
        ]);

        $user = $request->user();
        $customer = $user->customers()->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'error' => 'No customer account found',
            ], 404);
        }

        try {
            // First, update the payment method
            $user->updateDefaultPaymentMethod($request->payment_method_id);

            Log::info('AdSpendBilling: Payment method set during deployment setup', [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
            ]);

            // Calculate 7 days of ad spend
            $dailyBudget = $request->daily_budget;
            $initialCredit = $dailyBudget * 7;

            // Initialize the credit account (this will charge the customer)
            $credit = $this->billingService->initializeCreditAccount(
                $customer,
                $dailyBudget
            );

            Log::info('AdSpendBilling: Credit account initialized during deployment', [
                'customer_id' => $customer->id,
                'daily_budget' => $dailyBudget,
                'initial_credit' => $credit->initial_credit_amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ad spend billing set up successfully',
                'credit_amount' => $credit->initial_credit_amount,
                'new_balance' => $credit->current_balance,
            ]);
        } catch (\Exception $e) {
            Log::error('AdSpendBilling: Failed to setup for deployment', [
                'user_id' => $user->id,
                'customer_id' => $customer->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to set up ad spend billing: ' . $e->getMessage(),
            ], 400);
        }
    }
}
