<?php

namespace App\Jobs;

use App\Notifications\CriticalAgentAlert;
use App\Services\FacebookAds\TokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Verify the platform System User token is still valid.
 *
 * This replaced the per-customer token refresh job. Under the management
 * account pattern, there is a single System User token for all API calls.
 * This job simply verifies it hasn't expired and alerts admins if it has.
 *
 * Schedule: daily at 3:00 AM (existing schedule slot).
 */
class RefreshFacebookTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        $tokenService = new TokenService;
        $health = $tokenService->checkSystemTokenHealth();

        if (! $health['valid']) {
            Log::critical('Facebook System User token is invalid or missing', $health);

            CriticalAgentAlert::deliver(
                'facebook',
                'Facebook System User token is invalid',
                'Facebook System User token is invalid: '.($health['error'] ?? 'unknown error'),
                ['action_required' => 'Regenerate the System User token in Business Manager and update FACEBOOK_SYSTEM_USER_TOKEN in .env'],
                CriticalAgentAlert::RECIPIENTS_ADMINS
            );

            return;
        }

        Log::info('Facebook System User token health check passed', [
            'type' => $health['type'] ?? 'unknown',
            'scopes' => $health['scopes'] ?? [],
        ]);
    }
}
