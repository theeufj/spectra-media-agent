<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddAdSpendCreditRequest;
use App\Http\Requests\SetupAdSpendBillingRequest;
use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Services\AdSpendBillingService;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use App\Services\Creative\CreativeRelease;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Support\SafeError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdSpendBillingController extends Controller
{
    protected AdSpendBillingService $billingService;

    public function __construct(AdSpendBillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function confirmReservation(Request $request, int $reservation)
    {
        $customer = $this->getActiveCustomer($request);
        $payment = $customer ? app(\App\Services\Billing\LaunchFunding::class)->confirmationPayment($customer, $request->user(), $reservation) : null;
        abort_if($payment === null, 404);

        return redirect()->route('cashier.payment', ['id' => $payment, 'redirect' => route('billing.ad-spend')]);
    }

    /**
     * Show the ad spend billing dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return Inertia::render('Billing/AdSpend', [
                'credit' => null,
                'transactions' => [],
                'paymentFailed' => false,
                'error' => 'No customer account found',
            ]);
        }

        $credit = $customer->adSpendCredit;
        $payer = $customer->adSpendPayer();
        $dailyBudget = $customer->campaigns()->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('primary_status')->orWhereNotIn('primary_status', ['PAUSED', 'REMOVED', 'NOT_ELIGIBLE', 'ENDED']))
            ->sum('daily_budget');
        $dailyEstimate = $credit?->getAverageDailySpend() ?? 0;
        $topUpDailyBudget = $dailyBudget > 0 ? $dailyBudget : $dailyEstimate;

        return Inertia::render('Billing/AdSpend', [
            'reservations' => \Illuminate\Support\Facades\DB::table('launch_funding_reservations')->where('customer_id', $customer->id)
                ->whereIn('status', ['collecting', 'held'])->get(['id', 'platform', 'account_id', 'payer', 'currency', 'target_cents', 'held_cents', 'charge_cents', 'status']),
            'credit' => $credit ? [
                'id' => $credit->id,
                'initial_credit_amount' => $credit->initial_credit_amount,
                'current_balance' => $credit->current_balance,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'daily_budget' => $dailyBudget,
                'estimated_daily_spend' => $dailyEstimate,
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
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'description' => $t->description,
                    'created_at' => $t->created_at,
                ]) : [],
            'paymentFailed' => $credit && in_array($credit->payment_status, ['failed', 'paused', 'grace_period'], true),
            'paymentMethod' => $payer ? ['brand' => $payer->pm_type, 'last4' => $payer->pm_last_four, 'payer_name' => $payer->name] : null,
            'topUp' => ['daily_budget' => $topUpDailyBudget, 'estimated_amount' => round($topUpDailyBudget * 7, 2), 'threshold_days' => 3],
        ]);
    }

    /**
     * Get credit balance and status (API).
     */
    public function getBalance(Request $request)
    {
        $user = $request->user();
        $customer = $this->getActiveCustomer($request);

        if (! $customer || ! $customer->adSpendCredit) {
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
        $customer = $this->getActiveCustomer($request);

        if (! $customer || ! $customer->adSpendCredit) {
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
    public function addCredit(AddAdSpendCreditRequest $request)
    {

        $user = $request->user();
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
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
        $customer = $this->getActiveCustomer($request);

        if (! $customer || ! $customer->adSpendCredit) {
            return response()->json([
                'success' => false,
                'error' => 'No ad spend credit account found',
            ], 404);
        }

        $credit = $customer->adSpendCredit;

        // Read before charging: addCredit() restores the account, which clears
        // the paused state. Only a customer whose campaigns billing actually
        // paused should have them switched back on by a top-up.
        $campaignsWerePaused = $credit->payment_status === AdSpendCredit::PAYMENT_PAUSED;

        // Calculate amount needed to restore account
        $avgDailySpend = $credit->getAverageDailySpend();
        $replenishAmount = max(50, $avgDailySpend * 7); // At least $50, or 7 days of spend

        $result = $this->billingService->addCredit(
            $customer,
            $replenishAmount,
            'Payment recovery'
        );

        if ($result['success']) {
            // Restore the account status AND actually resume the paused campaigns +
            // restore their budgets — restoreAccount() alone only clears ledger flags.
            $credit->restoreAccount();
            $recovery = $this->billingService->recoverCampaigns($customer, $campaignsWerePaused);

            Log::info('AdSpendBilling: Payment recovered via retry', [
                'customer_id' => $customer->id,
                'amount' => $replenishAmount,
            ]);

            return response()->json([
                'success' => true,
                'new_balance' => $result['new_balance'],
                'message' => $this->paymentRecoveryMessage('Payment successful.', $recovery),
                'campaign_recovery' => $recovery,
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
        $customer = $this->getActiveCustomer($request);
        $billingUser = $customer?->adSpendPayer(allowWithoutPaymentMethod: true);

        if (! $customer || ! $billingUser) {
            return response()->json(['success' => false, 'error' => 'No customer account found'], 404);
        }

        try {
            if (! $billingUser->hasStripeId()) {
                $billingUser->createAsStripeCustomer();
            }
            $billingUser->updateDefaultPaymentMethod($request->payment_method_id);

            Log::info('AdSpendBilling: Payment method updated', [
                'user_id' => $user->id,
                'billing_user_id' => $billingUser->id,
                'customer_id' => $customer->id,
            ]);

            // If retry payment flag is set, also retry the failed payment
            if ($request->retry_payment) {
                if ($customer->adSpendCredit) {
                    $credit = $customer->adSpendCredit;

                    // Before the charge — addCredit() restores the account and
                    // clears the paused state.
                    $campaignsWerePaused = $credit->payment_status === AdSpendCredit::PAYMENT_PAUSED;

                    $avgDailySpend = $credit->getAverageDailySpend();
                    $replenishAmount = max(50, $avgDailySpend * 7);

                    $result = $this->billingService->addCredit(
                        $customer,
                        $replenishAmount,
                        'Payment recovery after payment method update'
                    );

                    if ($result['success']) {
                        $credit->restoreAccount();
                        $recovery = $this->billingService->recoverCampaigns($customer, $campaignsWerePaused);

                        return response()->json([
                            'success' => true,
                            'message' => $this->paymentRecoveryMessage('Payment method updated and payment successful.', $recovery),
                            'campaign_recovery' => $recovery,
                            'new_balance' => $result['new_balance'],
                        ]);
                    } else {
                        return response()->json([
                            'success' => false,
                            'error' => 'Payment method updated but payment still failed: '.($result['error'] ?? 'Unknown error'),
                        ], 400);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('AdSpendBilling: Failed to update payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => SafeError::message($e, "We couldn't update your payment method."),
            ], 400);
        }
    }

    /** @param array{resumed: int, failed: int, skipped: int} $recovery */
    private function paymentRecoveryMessage(string $paymentMessage, array $recovery): string
    {
        if ($recovery['failed'] > 0) {
            return $paymentMessage.' Some campaigns could not be restarted. Review their status before assuming ads are running.';
        }

        if ($recovery['skipped'] > 0) {
            return $paymentMessage.' Some campaigns still need review before further restart attempts.';
        }

        if ($recovery['resumed'] > 0) {
            return $paymentMessage.' Campaign resume requests were accepted.';
        }

        return $paymentMessage;
    }

    /**
     * Set up ad spend billing during deployment (first time setup).
     * This creates the credit account and charges the initial 7-day credit.
     */
    public function setupForDeployment(SetupAdSpendBillingRequest $request)
    {

        $user = $request->user();
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return response()->json([
                'success' => false,
                'error' => 'No customer account found',
            ], 404);
        }

        // Self-funded accounts are billed by the ad platform directly — the
        // deploy job refuses to draw down credit for them, so charging here
        // billed those customers twice for the same clicks.
        if ($customer->service_type === 'setup_only' || (! $request->campaign_id && $customer->isSelfFundedAds())) {
            return response()->json([
                'success' => false,
                'error' => 'Your ad spend is billed directly through your own ad account — no prepayment is needed. You can deploy right away.',
            ], 422);
        }

        if (! \App\Models\Setting::get('deployment_enabled', true)) {
            return response()->json(['success' => false, 'error' => 'Ad creation is currently unavailable. Funding will be available when creation resumes.'], 422);
        }

        if (! $customer->hasPaidDeploymentAccess()) {
            return response()->json(['success' => false, 'error' => 'Choose a paid campaign plan before funding ads.'], 422);
        }

        if (! $request->campaign_id) {
            return response()->json(['success' => false, 'error' => 'Choose the campaign and approved channel allocations before setting up its advertising payment.'], 422);
        }

        // Validate the same signed campaign before saving the intended payer's method.
        $campaign = \App\Models\Campaign::find($request->campaign_id);
        if (! $campaign || $campaign->customer_id !== $customer->id) {
            return response()->json(['success' => false, 'error' => 'Campaign not found'], 404);
        }
        if ($campaign->auto_generated_at && ! $campaign->budget_confirmed_at) {
            return response()->json([
                'success' => false,
                'error' => 'Please confirm this campaign\'s daily budget before funding it — open the campaign page and approve the proposed budget.',
                'requires_budget_confirmation' => true,
            ], 422);
        }
        $this->authorize('view', $campaign);
        $brand = $customer->brandGuideline;
        if (! $brand?->user_verified || ($brand->approved_version !== null && $brand->approved_version !== $brand->profile_version)) {
            return response()->json(['success' => false, 'error' => 'Review and confirm your current brand profile before funding this campaign.', 'requires_brand_confirmation' => true], 422);
        }
        $strategies = $campaign->strategies()->when($request->strategy_id, fn ($query) => $query->whereKey($request->strategy_id))->get();
        if ($request->strategy_id && $strategies->isEmpty()) {
            abort(404);
        }
        $approved = $strategies->whereNotNull('signed_off_at');
        if ($approved->isEmpty() || $approved->contains(fn ($strategy) => in_array($strategy->creative_review['status'] ?? null, ['pending', 'reviewing', 'revising'], true))) {
            return response()->json(['success' => false, 'error' => 'Finish the creative review and approve a strategy before funding this campaign.'], 422);
        }
        if (abs((float) $request->daily_budget - (float) $campaign->daily_budget) > 0.005) {
            return response()->json(['success' => false, 'error' => 'This campaign budget has changed. Reload the campaign and review the current daily budget before funding.'], 422);
        }
        if ($approved->contains(fn ($strategy) => ! $customer->allowsAdPlatform($strategy->platform))) {
            return response()->json(['success' => false, 'error' => 'Choose platforms covered by your plan before funding this campaign.'], 422);
        }
        $newStrategies = $approved->filter(function ($strategy) use ($campaign) {
            $strategy->setRelation('campaign', $campaign);

            return $strategy->requiresInitialMarketResearch();
        });
        if ($newStrategies->isNotEmpty()) {
            try {
                app(CampaignMarketResearchWorkflow::class)->assertApproved($campaign);
            } catch (ValidationException $e) {
                return response()->json(['success' => false, 'error' => $e->validator->errors()->first(), 'requires_market_research' => true], 422);
            }
        }
        $needsGoogle = $approved->count() === 1 && $approved->contains(fn ($strategy) => str_contains(strtolower($strategy->platform), 'google'));
        if ($needsGoogle && ! CampaignLaunchIntent::googleReady($customer) && ! CampaignLaunchIntent::canProvisionGoogle($customer)) {
            return response()->json(['success' => false, 'error' => 'Your Google Ads account needs setup. Submit the campaign for account setup first; no prepayment is collected yet.', 'requires_account_setup' => true], 422);
        }
        foreach ($approved->reject(fn ($strategy) => $strategy->isDeployed()) as $strategy) {
            try {
                app(CreativeRelease::class)->assertReady($strategy, $request->input('creative_fingerprints.'.$strategy->id));
            } catch (ValidationException $e) {
                return response()->json(['success' => false, 'error' => $e->validator->errors()->first()], 422);
            }
        }

        try {
            $billingUser = $customer->adSpendPayer(allowWithoutPaymentMethod: (bool) $request->payment_method_id);
            if (! $billingUser) {
                return response()->json(['success' => false, 'error' => 'No payment method on file. Please add a payment method.'], 400);
            }

            if (! $billingUser->stripe_id) {
                $billingUser->createAsStripeCustomer();
            }

            // Update payment method if a new one was provided; otherwise use the existing one
            if ($request->payment_method_id) {
                $billingUser->updateDefaultPaymentMethod($request->payment_method_id);
            } elseif (! $billingUser->hasDefaultPaymentMethod()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No payment method on file. Please add a payment method.',
                ], 400);
            }

            // Final creative/budget approval is the only action that may collect a channel prepayment.
            return response()->json(['success' => true, 'deferred' => true, 'credit_amount' => 0,
                'new_balance' => $customer->adSpendCredit()->value('current_balance') ?? '0.00',
                'message' => 'Payment method saved. Each channel reserves up to seven days after approval and verification.']);
        } catch (\Throwable $e) {
            report($e);
            Log::error('AdSpendBilling: Failed to setup for deployment', [
                'user_id' => $user->id,
                'customer_id' => $customer->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => SafeError::message($e, "We couldn't set up ad spend billing."),
            ], 400);
        }
    }
}
