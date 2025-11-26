<?php

namespace App\Services;

use App\Models\AdSpendCredit;
use App\Models\AdSpendTransaction;
use App\Models\Campaign;
use App\Models\Customer;
use App\Mail\AdSpendPaymentWarning;
use App\Mail\AdSpendPaymentFailed;
use App\Mail\AdSpendCampaignsPaused;
use App\Mail\AdSpendCampaignsResumed;
use App\Mail\AdSpendLowBalance;
use App\Services\Agents\BudgetIntelligenceAgent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\CardException;

/**
 * AdSpendBillingService
 * 
 * Handles all ad spend billing logic:
 * - Initial credit capture on campaign creation
 * - Daily billing for actual ad spend
 * - Payment failure handling with grace periods
 * - Campaign pausing/resuming based on payment status
 * 
 * BILLING FLOW:
 * 1. Campaign created → Charge 7 days estimated spend upfront
 * 2. Daily at midnight → Calculate actual spend, deduct from credit
 * 3. If credit < 3 days remaining → Auto-replenish from card
 * 4. If payment fails → Enter grace period (24h)
 * 5. If still failing after grace → Reduce budget 50%
 * 6. If failing after 48h → Pause all campaigns
 * 7. Payment recovered → Auto-resume campaigns
 */
class AdSpendBillingService
{
    protected BudgetIntelligenceAgent $budgetAgent;

    public function __construct(?BudgetIntelligenceAgent $budgetAgent = null)
    {
        $this->budgetAgent = $budgetAgent ?? app(BudgetIntelligenceAgent::class);
    }

    /**
     * Initialize credit account for a customer when they create their first campaign.
     */
    public function initializeCreditAccount(Customer $customer, float $dailyBudget): AdSpendCredit
    {
        // Check if customer already has a credit account
        $existing = $customer->adSpendCredit;
        if ($existing) {
            return $existing;
        }

        // Calculate initial credit (7 days of estimated spend)
        $initialCredit = AdSpendCredit::calculateInitialCredit($dailyBudget, 7);

        // Charge the customer's card for the initial credit
        $chargeResult = $this->chargeCustomer($customer, $initialCredit, 'Initial ad spend credit (7 days)');

        if (!$chargeResult['success']) {
            throw new \Exception('Failed to charge initial ad spend credit: ' . $chargeResult['error']);
        }

        // Create the credit account
        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => $initialCredit,
            'current_balance' => $initialCredit,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
            'last_successful_charge_at' => now(),
            'stripe_payment_method_id' => $chargeResult['payment_method_id'] ?? null,
        ]);

        // Record the initial credit transaction
        $credit->transactions()->create([
            'type' => AdSpendTransaction::TYPE_CREDIT,
            'amount' => $initialCredit,
            'balance_after' => $initialCredit,
            'description' => 'Initial ad spend credit (7 days prepaid)',
            'stripe_charge_id' => $chargeResult['charge_id'] ?? null,
        ]);

        Log::info('AdSpendBilling: Initialized credit account', [
            'customer_id' => $customer->id,
            'initial_credit' => $initialCredit,
        ]);

        return $credit;
    }

    /**
     * Process daily billing for a customer.
     * Called by the scheduled job each day.
     */
    public function processDailyBilling(Customer $customer): array
    {
        $result = [
            'customer_id' => $customer->id,
            'success' => false,
            'actual_spend' => 0,
            'action_taken' => null,
            'error' => null,
        ];

        try {
            $credit = $customer->adSpendCredit;
            
            if (!$credit) {
                $result['error'] = 'No credit account found';
                return $result;
            }

            // If campaigns are already paused, check if we should try to recover
            if ($credit->payment_status === AdSpendCredit::PAYMENT_PAUSED) {
                return $this->attemptPaymentRecovery($customer, $credit);
            }

            // Get actual ad spend from yesterday
            $actualSpend = $this->getActualAdSpend($customer);
            $result['actual_spend'] = $actualSpend;

            if ($actualSpend <= 0) {
                $result['success'] = true;
                $result['action_taken'] = 'No spend to bill';
                return $result;
            }

            // Deduct from credit balance
            if ($credit->current_balance >= $actualSpend) {
                $credit->deduct($actualSpend, 'Daily ad spend - ' . now()->subDay()->format('Y-m-d'));
                $result['success'] = true;
                $result['action_taken'] = 'Deducted from credit balance';

                // Check if we need to auto-replenish
                $this->checkAndReplenish($customer, $credit);
            } else {
                // Not enough credit, need to charge card
                $shortfall = $actualSpend - $credit->current_balance;
                
                // Deduct whatever is available
                if ($credit->current_balance > 0) {
                    $credit->deduct($credit->current_balance, 'Daily ad spend (partial) - ' . now()->subDay()->format('Y-m-d'));
                }

                // Try to charge the shortfall plus replenishment
                $replenishAmount = AdSpendCredit::calculateInitialCredit(
                    $this->getAverageDailyBudget($customer), 
                    7
                );
                $totalToCharge = $shortfall + $replenishAmount;

                $chargeResult = $this->chargeCustomer($customer, $totalToCharge, 'Ad spend replenishment');

                if ($chargeResult['success']) {
                    $credit->addCredit($totalToCharge, 'Credit replenishment', $chargeResult['charge_id']);
                    $credit->deduct($shortfall, 'Daily ad spend (remaining) - ' . now()->subDay()->format('Y-m-d'));
                    $credit->restoreAccount();
                    $result['success'] = true;
                    $result['action_taken'] = 'Charged card and replenished credit';
                } else {
                    // Payment failed - enter grace period or escalate
                    $this->handlePaymentFailure($customer, $credit, $chargeResult['error']);
                    $result['success'] = false;
                    $result['error'] = 'Payment failed: ' . $chargeResult['error'];
                    $result['action_taken'] = 'Entered payment failure flow';
                }
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('AdSpendBilling: Daily billing failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = $e->getMessage();
            return $result;
        }
    }

    /**
     * Handle payment failure with grace period logic.
     */
    protected function handlePaymentFailure(Customer $customer, AdSpendCredit $credit, string $error): void
    {
        Log::warning('AdSpendBilling: Payment failed', [
            'customer_id' => $customer->id,
            'failed_count' => $credit->failed_charge_count + 1,
            'error' => $error,
        ]);

        $user = $customer->users()->first();

        // Determine action based on failure count
        switch ($credit->failed_charge_count) {
            case 0:
                // First failure - enter 24-hour grace period
                $credit->enterGracePeriod(24);
                
                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendPaymentWarning($customer, $credit, $error));
                }
                break;

            case 1:
                // Second failure - extend grace period, reduce budget
                $credit->markPaymentFailed();
                $this->reduceCampaignBudgets($customer, 0.5); // 50% budget
                
                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendPaymentFailed($customer, $credit, $error));
                }
                break;

            default:
                // Third+ failure - pause all campaigns
                $credit->pauseCampaigns();
                $this->pauseAllCampaigns($customer);
                
                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendCampaignsPaused($customer, $credit));
                }
                break;
        }
    }

    /**
     * Attempt to recover payment and resume campaigns.
     */
    protected function attemptPaymentRecovery(Customer $customer, AdSpendCredit $credit): array
    {
        $result = [
            'customer_id' => $customer->id,
            'success' => false,
            'actual_spend' => 0,
            'action_taken' => null,
            'error' => null,
        ];

        // Calculate what we need to charge to get back to healthy state
        $replenishAmount = AdSpendCredit::calculateInitialCredit(
            $this->getAverageDailyBudget($customer), 
            7
        );

        $chargeResult = $this->chargeCustomer($customer, $replenishAmount, 'Ad spend recovery');

        if ($chargeResult['success']) {
            $credit->addCredit($replenishAmount, 'Credit recovery', $chargeResult['charge_id']);
            $credit->restoreAccount();
            
            // Resume campaigns
            $this->resumeAllCampaigns($customer);
            
            // Restore budgets to 100%
            $this->reduceCampaignBudgets($customer, 1.0);

            $user = $customer->users()->first();
            if ($user) {
                Mail::to($user->email)->queue(new AdSpendCampaignsResumed($customer, $credit));
            }

            $result['success'] = true;
            $result['action_taken'] = 'Payment recovered, campaigns resumed';

            Log::info('AdSpendBilling: Payment recovered', [
                'customer_id' => $customer->id,
            ]);
        } else {
            $result['error'] = 'Recovery payment failed: ' . $chargeResult['error'];
            $result['action_taken'] = 'Recovery attempt failed';
        }

        return $result;
    }

    /**
     * Check if credit needs replenishment and auto-charge.
     */
    protected function checkAndReplenish(Customer $customer, AdSpendCredit $credit): void
    {
        $avgDailySpend = $credit->getAverageDailySpend();
        $daysRemaining = $avgDailySpend > 0 ? $credit->current_balance / $avgDailySpend : 999;

        // If less than 3 days remaining, auto-replenish
        if ($daysRemaining < 3 && $daysRemaining > 0) {
            $replenishAmount = AdSpendCredit::calculateInitialCredit($avgDailySpend, 7);
            
            $chargeResult = $this->chargeCustomer($customer, $replenishAmount, 'Auto-replenishment');

            if ($chargeResult['success']) {
                $credit->addCredit($replenishAmount, 'Auto-replenishment', $chargeResult['charge_id']);
                
                Log::info('AdSpendBilling: Auto-replenished credit', [
                    'customer_id' => $customer->id,
                    'amount' => $replenishAmount,
                ]);
            } else {
                // Send low balance warning
                $user = $customer->users()->first();
                if ($user) {
                    Mail::to($user->email)->queue(new AdSpendLowBalance($customer, $credit, $daysRemaining));
                }
            }
        }
    }

    /**
     * Charge the customer's card via Stripe.
     */
    protected function chargeCustomer(Customer $customer, float $amount, string $description): array
    {
        try {
            $user = $customer->users()->first();
            
            if (!$user || !$user->hasDefaultPaymentMethod()) {
                return [
                    'success' => false,
                    'error' => 'No payment method on file',
                ];
            }

            // Amount in cents for Stripe
            $amountCents = (int) round($amount * 100);

            // Use Laravel Cashier to charge
            $payment = $user->charge($amountCents, $user->defaultPaymentMethod()->id, [
                'description' => $description,
                'metadata' => [
                    'customer_id' => $customer->id,
                    'type' => 'ad_spend_credit',
                ],
            ]);

            return [
                'success' => true,
                'charge_id' => $payment->id,
                'payment_method_id' => $user->defaultPaymentMethod()->id,
            ];

        } catch (CardException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } catch (IncompletePayment $e) {
            return [
                'success' => false,
                'error' => 'Payment requires additional action',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get actual ad spend from Google/Facebook Ads APIs.
     */
    protected function getActualAdSpend(Customer $customer): float
    {
        $totalSpend = 0;

        try {
            // Get spend from active campaigns
            $activeCampaigns = $customer->campaigns()
                ->where('status', 'active')
                ->whereNotNull('external_campaign_id')
                ->get();

            foreach ($activeCampaigns as $campaign) {
                if ($campaign->platform === 'google') {
                    $spend = $this->getGoogleAdsSpend($customer, $campaign);
                } elseif ($campaign->platform === 'facebook') {
                    $spend = $this->getFacebookAdsSpend($customer, $campaign);
                } else {
                    continue;
                }

                $totalSpend += $spend;
            }

        } catch (\Exception $e) {
            Log::error('AdSpendBilling: Failed to get actual spend', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $totalSpend;
    }

    /**
     * Get spend from Google Ads for yesterday.
     */
    protected function getGoogleAdsSpend(Customer $customer, Campaign $campaign): float
    {
        try {
            $performanceService = app(\App\Services\GoogleAds\CommonServices\GetCampaignPerformance::class);
            
            $performance = $performanceService->getPerformance(
                $customer->google_ads_customer_id,
                $campaign->external_campaign_id,
                now()->subDay()->format('Y-m-d'),
                now()->subDay()->format('Y-m-d')
            );

            // Cost is returned in micros, convert to dollars
            return ($performance['cost_micros'] ?? 0) / 1_000_000;

        } catch (\Exception $e) {
            Log::warning('AdSpendBilling: Failed to get Google Ads spend', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get spend from Facebook Ads for yesterday.
     */
    protected function getFacebookAdsSpend(Customer $customer, Campaign $campaign): float
    {
        // TODO: Implement Facebook Ads spend retrieval
        return 0;
    }

    /**
     * Get average daily budget across all customer campaigns.
     */
    protected function getAverageDailyBudget(Customer $customer): float
    {
        return $customer->campaigns()
            ->where('status', 'active')
            ->avg('daily_budget') ?? 50; // Default to $50 if no campaigns
    }

    /**
     * Reduce budgets for all active campaigns.
     */
    protected function reduceCampaignBudgets(Customer $customer, float $multiplier): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->whereNotNull('external_campaign_id')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                $this->budgetAgent->applyBudgetMultiplier($customer, $campaign, $multiplier);
            } catch (\Exception $e) {
                Log::error('AdSpendBilling: Failed to reduce budget', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Pause all active campaigns for a customer.
     */
    protected function pauseAllCampaigns(Customer $customer): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'active')
            ->whereNotNull('external_campaign_id')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                // TODO: Call Google Ads / Facebook Ads API to pause campaign
                // For now, just mark as paused in our database
                $campaign->update([
                    'status' => 'paused',
                    'paused_reason' => 'Payment failure',
                    'paused_at' => now(),
                ]);

                Log::info('AdSpendBilling: Paused campaign', [
                    'campaign_id' => $campaign->id,
                    'reason' => 'payment_failure',
                ]);

            } catch (\Exception $e) {
                Log::error('AdSpendBilling: Failed to pause campaign', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resume all paused campaigns for a customer.
     */
    protected function resumeAllCampaigns(Customer $customer): void
    {
        $campaigns = $customer->campaigns()
            ->where('status', 'paused')
            ->where('paused_reason', 'Payment failure')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                // TODO: Call Google Ads / Facebook Ads API to enable campaign
                $campaign->update([
                    'status' => 'active',
                    'paused_reason' => null,
                    'paused_at' => null,
                ]);

                Log::info('AdSpendBilling: Resumed campaign', [
                    'campaign_id' => $campaign->id,
                ]);

            } catch (\Exception $e) {
                Log::error('AdSpendBilling: Failed to resume campaign', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Add credit to a customer's account (manual top-up).
     */
    public function addCredit(Customer $customer, float $amount, string $description = null): array
    {
        $credit = $customer->adSpendCredit;
        
        if (!$credit) {
            return [
                'success' => false,
                'error' => 'No credit account found',
            ];
        }

        $chargeResult = $this->chargeCustomer($customer, $amount, $description ?? 'Manual credit top-up');

        if ($chargeResult['success']) {
            $credit->addCredit($amount, $description ?? 'Manual credit top-up', $chargeResult['charge_id']);
            
            return [
                'success' => true,
                'new_balance' => $credit->current_balance,
                'charge_id' => $chargeResult['charge_id'],
            ];
        }

        return $chargeResult;
    }
}
