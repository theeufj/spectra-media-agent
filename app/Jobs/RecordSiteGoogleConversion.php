<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a server-side Google Ads conversion for a user who arrived via a
 * Google Ad, using the Data Manager API.
 *
 * Sibling of RecordSiteFacebookConversion / RecordSiteMicrosoftConversion,
 * which already covered fbclid and msclid — Google was the gap. Registration
 * signals had no server-side path at all: the code assumed gtag handled signup
 * client-side, but nothing ever called trackConversion('signup'), so across 22
 * real registrations Google recorded zero. With Maximize Conversions bidding on
 * an empty conversion history, the campaign lost ~90% of impression share to
 * Ad Rank.
 *
 * Server-side is the right home for this: registration completes on the server
 * where the gclid is already stored on the user, so it cannot be lost to ad
 * blockers, consent gating, or a redirect landing on a page that never fires.
 *
 * Targets an UPLOAD_CLICKS conversion action — Google rejects click uploads
 * against WEBPAGE-type actions.
 */
class RecordSiteGoogleConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300];

    /**
     * Own-site event key → the UPLOAD_CLICKS conversion action it uploads to.
     * Client-side (WEBPAGE) events are absent by design: they fire via gtag and
     * uploading them as well would double-count.
     */
    private const UPLOAD_ACTIONS = [
        'signup' => 'signup_import',
        'campaign_live' => 'campaign_live',
        'seven_day_return' => 'seven_day_return',
    ];

    public function __construct(
        protected User $user,
        protected string $event
    ) {}

    public function handle(DataManagerService $dataManager): void
    {
        if (! $this->user->gclid) {
            return;
        }

        $actionKey = self::UPLOAD_ACTIONS[$this->event] ?? null;
        if (! $actionKey) {
            Log::debug("RecordSiteGoogleConversion: no upload action mapped for '{$this->event}'");

            return;
        }

        $resourceName = Setting::get("conversion_resource_name.{$actionKey}");
        if (! $resourceName) {
            Log::warning("RecordSiteGoogleConversion: '{$actionKey}' not provisioned — run conversions:provision");

            return;
        }

        // customers/{operatingAccountId}/conversionActions/{conversionActionId}
        $parts = explode('/', $resourceName);
        $operatingAccountId = $parts[1] ?? null;
        $conversionActionId = $parts[3] ?? null;
        if (! $operatingAccountId || ! $conversionActionId) {
            Log::error("RecordSiteGoogleConversion: unparseable resource_name '{$resourceName}'");

            return;
        }

        $config = config("conversions.events.{$this->event}", []);

        $result = $dataManager->ingestGclidConversion(
            operatingAccountId: (string) $operatingAccountId,
            conversionActionId: (string) $conversionActionId,
            gclid: $this->user->gclid,
            value: (float) ($config['value'] ?? 0),
            currency: $config['currency'] ?? 'USD',
            // The click, not "now" — Google attributes on the conversion
            // timestamp and rejects anything outside the click lookback window.
            occurredAt: $this->user->created_at ?? now(),
            email: $this->user->email,
        );

        SpectraConversionEvent::record($this->event, $this->user->id, [
            'gclid' => $this->user->gclid,
            'mode' => 'server_google',
            'uploaded' => $result['success'],
        ]);

        if ($result['success']) {
            Log::info("RecordSiteGoogleConversion: uploaded '{$this->event}' for user {$this->user->id} (request ".($result['requestId'] ?? 'n/a').')');
        } else {
            Log::warning("RecordSiteGoogleConversion: upload failed for '{$this->event}': ".($result['error'] ?? 'unknown'), [
                'user_id' => $this->user->id,
            ]);
        }
    }
}
