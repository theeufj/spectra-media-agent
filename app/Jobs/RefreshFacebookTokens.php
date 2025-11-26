<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\FacebookAds\TokenService;
use App\Mail\FacebookTokenExpiringMail;
use App\Mail\FacebookTokenExpiredMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

/**
 * Job to refresh Facebook tokens that are expiring soon.
 * Should be scheduled to run daily.
 */
class RefreshFacebookTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('RefreshFacebookTokens: Starting token refresh job');

        $tokenService = new TokenService();
        
        // Get all customers with Facebook tokens
        $customers = Customer::whereNotNull('facebook_ads_access_token')->get();
        
        $stats = [
            'total' => $customers->count(),
            'refreshed' => 0,
            'failed' => 0,
            'not_needed' => 0,
            'expired' => 0,
        ];

        foreach ($customers as $customer) {
            try {
                $status = $tokenService->checkTokenStatus($customer);

                // Token already expired or invalid
                if (!$status['valid']) {
                    $stats['expired']++;
                    $this->notifyTokenExpired($customer);
                    continue;
                }

                // Token expiring within 7 days - needs refresh
                if ($status['needs_refresh']) {
                    $result = $tokenService->refreshCustomerTokenIfNeeded($customer);
                    
                    if ($result['refreshed']) {
                        $stats['refreshed']++;
                        Log::info('RefreshFacebookTokens: Token refreshed', [
                            'customer_id' => $customer->id,
                        ]);
                    } elseif (!$result['success']) {
                        $stats['failed']++;
                        
                        // If refresh failed and token expires within 3 days, notify user
                        if (($status['expires_in_days'] ?? 0) <= 3) {
                            $this->notifyTokenExpiring($customer, $status['expires_in_days'] ?? 0);
                        }
                        
                        Log::warning('RefreshFacebookTokens: Token refresh failed', [
                            'customer_id' => $customer->id,
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                    }
                } else {
                    $stats['not_needed']++;
                }

            } catch (\Exception $e) {
                $stats['failed']++;
                Log::error('RefreshFacebookTokens: Exception processing customer', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('RefreshFacebookTokens: Job completed', $stats);
    }

    /**
     * Notify users that their token is expiring soon.
     */
    protected function notifyTokenExpiring(Customer $customer, int $daysRemaining): void
    {
        try {
            $user = $customer->users()->first();
            
            if ($user) {
                // Check if we've already notified recently (prevent spam)
                $lastNotified = cache()->get("fb_token_expiring_notified:{$customer->id}");
                
                if (!$lastNotified) {
                    Mail::to($user->email)->queue(new FacebookTokenExpiringMail($customer, $daysRemaining));
                    cache()->put("fb_token_expiring_notified:{$customer->id}", true, now()->addHours(24));
                    
                    Log::info('Sent Facebook token expiring notification', [
                        'customer_id' => $customer->id,
                        'user_email' => $user->email,
                        'days_remaining' => $daysRemaining,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send token expiring notification: ' . $e->getMessage());
        }
    }

    /**
     * Notify users that their token has expired.
     */
    protected function notifyTokenExpired(Customer $customer): void
    {
        try {
            $user = $customer->users()->first();
            
            if ($user) {
                // Check if we've already notified recently
                $lastNotified = cache()->get("fb_token_expired_notified:{$customer->id}");
                
                if (!$lastNotified) {
                    Mail::to($user->email)->queue(new FacebookTokenExpiredMail($customer));
                    cache()->put("fb_token_expired_notified:{$customer->id}", true, now()->addDays(3));
                    
                    Log::info('Sent Facebook token expired notification', [
                        'customer_id' => $customer->id,
                        'user_email' => $user->email,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send token expired notification: ' . $e->getMessage());
        }
    }
}
