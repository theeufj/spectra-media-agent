<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Plan;
use App\Models\PerformanceData;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ReportAdSpendUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:report-ad-spend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and report daily ad spend for all subscribed customers to Stripe.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Ad spend is billed through the managed prepaid-credit system
        // (ProcessDailyAdSpendBilling / AdSpendCredit). This Stripe-metered path is a
        // second, mutually-exclusive billing model and is OFF by default so the two can
        // never double-charge the same customer. It also previously called the
        // Cashier v15 `recordUsageFor()` which no longer exists in v16 and threw on
        // every run — silently invoicing $0. Only enable this once metered billing is
        // the chosen model for a tier AND a Stripe meter is configured.
        if (!config('billing.metered_ad_spend_enabled', false)) {
            $this->info('Metered ad-spend reporting is disabled (managed-credit billing is authoritative). Skipping.');
            Log::info('billing:report-ad-spend skipped — metered billing disabled.');
            return 0;
        }

        $meterName = config('billing.ad_spend_meter');
        if (!$meterName) {
            $this->error('Metered ad-spend reporting is enabled but no billing.ad_spend_meter is configured.');
            Log::error('billing:report-ad-spend enabled but billing.ad_spend_meter is not set.');
            return 1;
        }

        $this->info('Starting daily ad spend reporting...');
        Log::info('Starting daily ad spend reporting job.');

        $yesterday = Carbon::yesterday();

        // Get all users who are active subscribers
        $subscribedUsers = User::whereHas('subscriptions')->get();

        foreach ($subscribedUsers as $user) {
            try {
                // Find the user's subscription
                $subscription = $user->subscription('default');
                if (!$subscription) {
                    continue;
                }

                // Resolve the ad spend price ID from the user's plan
                $plan = Plan::where('stripe_price_id', $subscription->stripe_price)->first();
                $adSpendPriceId = $plan?->stripe_ad_spend_price_id;

                if (!$adSpendPriceId) {
                    $this->line("No ad spend price configured for user {$user->id}'s plan, skipping.");
                    continue;
                }

                // Calculate the total ad spend for this user's campaigns yesterday
                $totalSpend = PerformanceData::whereHas('strategy.campaign', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->whereDate('created_at', $yesterday)
                ->sum('spend');

                if ($totalSpend > 0) {
                    // Report usage to Stripe. The quantity should be in the smallest currency unit (cents).
                    $usageQuantity = (int) round($totalSpend * 100);

                    // Cashier v16: report a meter event (recordUsageFor was removed).
                    $user->reportMeterEvent($meterName, $usageQuantity);

                    $this->info("Reported {$usageQuantity} cents of ad spend for user {$user->id}.");
                    Log::info("Reported usage for user {$user->id}: {$usageQuantity} cents.");
                } else {
                    $this->line("No ad spend to report for user {$user->id} for {$yesterday->toDateString()}.");
                }
            } catch (\Exception $e) {
                $this->error("Failed to report ad spend for user {$user->id}: {$e->getMessage()}");
                Log::error("Failed to report ad spend for user {$user->id}:", ['exception' => $e]);
            }
        }

        $this->info('Daily ad spend reporting complete.');
        Log::info('Daily ad spend reporting job finished.');
        return 0;
    }
}
