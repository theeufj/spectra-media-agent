<?php

namespace App\Console\Commands;

use App\Models\User;
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
        $this->info('Starting daily ad spend reporting...');
        Log::info('Starting daily ad spend reporting job.');

        $yesterday = Carbon::yesterday();
        $adSpendPriceId = env('STRIPE_AD_SPEND_PRICE_ID');

        if (!$adSpendPriceId) {
            $this->error('STRIPE_AD_SPEND_PRICE_ID is not set in the .env file.');
            Log::error('STRIPE_AD_SPEND_PRICE_ID is not set.');
            return 1;
        }

        // Get all users who are active subscribers
        $subscribedUsers = User::whereHas('subscriptions')->get();

        foreach ($subscribedUsers as $user) {
            try {
                // Find the user's subscription
                $subscription = $user->subscription('default');
                if (!$subscription) {
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
                    
                    $user->recordUsageFor($adSpendPriceId, $usageQuantity);

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
