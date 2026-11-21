<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\OfflineConversion;
use App\Services\FacebookAds\ConversionsApiService;
use App\Services\GoogleAds\DataManagerService;
use App\Services\MicrosoftAds\ConversionTrackingService as MicrosoftConversionTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadOfflineConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Rows processed per run; a full batch self-redispatches for the remainder. */
    protected const BATCH_SIZE = 200;

    /** Max upload attempts before a failed row is left alone (avoids hammering a bad row). */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected int $customerId
    ) {}

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('offline-purchase:'.$this->customerId))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            return;
        }

        // Include previously-failed rows that still have retries left, so a transient
        // ad-network outage doesn't cause permanent conversion loss. (JOB-3)
        $pending = OfflineConversion::where('customer_id', $this->customerId)
            ->where(function ($q) {
                $q->where('upload_status', 'pending')
                    ->orWhere(function ($q) {
                        $q->where('upload_status', 'failed')
                            ->where('upload_attempts', '<', self::MAX_ATTEMPTS);
                    });
            })
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        // Per-platform idempotency: a retried row (upload_status='failed') may already
        // have succeeded on one platform, so gate each platform on its own recorded
        // status in upload_results — never re-upload a platform that already succeeded
        // (which would double-count the conversion). (JOB-3)
        $alreadyUploaded = fn ($c, $platform) => ($c->upload_results[$platform]['status'] ?? null) === 'uploaded';

        // Rows settled this run — uploaded, or charged an attempt. The
        // re-dispatch below hangs off it, so every path that leaves the batch
        // untouched must return 0.
        $settled = 0;

        // Upload to Google Ads (conversions with gclid)
        $googleConversions = $pending->filter(fn ($c) => ! empty($c->gclid) && ! $alreadyUploaded($c, 'google_ads'));
        if ($googleConversions->isNotEmpty()) {
            $settled += $this->uploadToGoogleAds($customer, $googleConversions);
        }

        // Upload to Facebook (conversions with fbclid)
        $facebookConversions = $pending->filter(fn ($c) => ! empty($c->fbclid) && ! $alreadyUploaded($c, 'facebook'));
        if ($facebookConversions->isNotEmpty()) {
            $settled += $this->uploadToFacebook($customer, $facebookConversions);
        }

        // Upload to Microsoft (conversions with msclkid)
        $microsoftConversions = $pending->filter(fn ($c) => ! empty($c->msclid) && ! $alreadyUploaded($c, 'microsoft'));
        if ($microsoftConversions->isNotEmpty()) {
            $settled += $this->uploadToMicrosoft($customer, $microsoftConversions);
        }

        // Rows carrying no click identifier at all. No platform can attribute
        // them, so none of the three branches above ever sees them: left alone
        // they stay `pending` for good, refill every batch ahead of newer rows
        // and keep the run looking like it has work to do.
        $unattributable = $pending->filter(
            fn ($c) => empty($c->gclid) && empty($c->fbclid) && empty($c->msclid)
        );
        if ($unattributable->isNotEmpty()) {
            $settled += $this->chargeAttempt(
                $unattributable,
                'unattributable',
                'No gclid, fbclid or msclid to attribute the conversion against'
            );
        }

        // A full batch means more rows are likely waiting — self-redispatch for them.
        //
        // Gated on having settled something. An account that is simply not
        // configured for any platform (no Google customer id, no pixel, no UET
        // tag) returns from every branch above without touching a row, so the
        // next run selects the same full batch and re-dispatches again: the
        // unguarded version re-queued this job every minute, for ever, and the
        // hourly RetryOfflineConversions started a fresh chain alongside it.
        if ($settled > 0 && $pending->count() >= self::BATCH_SIZE) {
            self::dispatch($this->customerId)->delay(now()->addMinute());
        }
    }

    /**
     * Record a platform's refusal against rows it never got as far as sending.
     *
     * The early returns below (no account id, no client, no conversion action)
     * left every row exactly as they found it — still `pending`, attempts still
     * 0 — so MAX_ATTEMPTS could never retire a row no configuration will ever
     * make uploadable. Charging the attempt is what lets one eventually drop out
     * of the pending set instead of blocking the batch behind it.
     *
     * @return int rows touched
     */
    protected function chargeAttempt($conversions, string $platform, string $error): int
    {
        $touched = 0;

        foreach ($conversions as $conversion) {
            $results = $conversion->upload_results ?? [];

            // Never overwrite a success recorded earlier in this same run: the
            // per-platform marker is the only thing stopping a re-upload, and a
            // re-upload double-counts the conversion.
            if (($results[$platform]['status'] ?? null) === 'uploaded') {
                continue;
            }

            $results[$platform] = [
                'status' => 'failed',
                'error' => $error,
                'attempted_at' => now()->toDateTimeString(),
            ];

            $conversion->update([
                'upload_status' => 'failed',
                'upload_results' => $results,
                'upload_attempts' => $conversion->upload_attempts + 1,
            ]);

            $touched++;
        }

        return $touched;
    }

    /** @return int rows settled — uploaded or charged an attempt */
    protected function uploadToGoogleAds(Customer $customer, $conversions): int
    {
        return $this->uploadPurchases($customer, $conversions, 'google_ads');
    }

    protected function uploadToFacebook(Customer $customer, $conversions): int
    {
        return $this->uploadPurchases($customer, $conversions, 'facebook');
    }

    private function uploadPurchases(Customer $customer, $conversions, string $platform): int
    {
        foreach ($conversions as $conversion) {
            try {
                $conversion->refresh();
                $results = $conversion->upload_results ?? [];
                if (($results[$platform]['status'] ?? null) === 'uploaded') {
                    continue;
                }
                $google = $platform === 'google_ads';
                $binding = $conversion->delivery_bindings[$google ? 'google' : 'facebook'] ?? [];
                $account = $google ? $customer->cleanGoogleCustomerId() : preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
                $destination = $binding['destination'] ?? null;
                if (($conversion->delivery_bindings['ad_user_data'] ?? '') !== 'granted' || ! $conversion->source_key || ! $conversion->crm_integration_id || ! $account || ($binding['account'] ?? null) !== $account
                    || ($binding['event'] ?? '') !== 'Purchase' || ! $destination || $conversion->conversion_value <= 0) {
                    throw new \RuntimeException('A verified CRM sale and one selected purchase destination on this account are required.');
                }
                if ($google) {
                    $details = app(\App\Services\GoogleAds\ConversionTrackingService::class, ['customer' => $customer])->outcomeDestination($account, $destination);
                    if (($details['type'] ?? null) !== 'UPLOAD_CLICKS' || ($details['category'] ?? null) !== 'PURCHASE') {
                        throw new \RuntimeException('Select an enabled Google purchase import action.');
                    }
                    $result = app(DataManagerService::class)->ingestConversion($account, basename($destination), ['gclid' => $conversion->gclid],
                        (float) $conversion->conversion_value, $conversion->currency_code, $conversion->conversion_time, eventSource: 'OTHER', transactionId: $conversion->source_key, consent: ['adUserData' => 'CONSENT_GRANTED']);
                    if (! $result['success'] || ! filled($result['requestId'] ?? null)) {
                        throw new \RuntimeException('Google did not return a CRM purchase receipt.');
                    }
                    $receipt = $result['requestId'];
                } else {
                    if ($customer->facebook_pixel_id !== $destination || ! app(\App\Services\FacebookAds\PixelService::class, ['customer' => $customer])->isLinked($destination, $account)) {
                        throw new \RuntimeException('The selected Meta purchase dataset is unavailable.');
                    }
                    $result = app(ConversionsApiService::class, ['customer' => $customer])->sendEvent($destination, [
                        'event_name' => 'Purchase', 'event_id' => $conversion->source_key, 'event_time' => $conversion->conversion_time->timestamp,
                        'action_source' => 'system_generated', 'user_data' => ['fbc' => 'fb.1.'.($conversion->conversion_time->timestamp * 1000).'.'.$conversion->fbclid],
                        'custom_data' => ['value' => (float) $conversion->conversion_value, 'currency' => $conversion->currency_code],
                    ]);
                    if (($result['events_received'] ?? null) !== 1 || ! filled($result['fbtrace_id'] ?? null)) {
                        throw new \RuntimeException('Meta did not acknowledge the CRM purchase.');
                    }
                    $receipt = $result['fbtrace_id'];
                }
                $results[$platform] = ['status' => 'uploaded', 'destination' => $destination, 'request_id' => $receipt, 'uploaded_at' => now()->toIso8601String()];
                $remaining = ($conversion->gclid && ($results['google_ads']['status'] ?? '') !== 'uploaded')
                    || ($conversion->fbclid && ($results['facebook']['status'] ?? '') !== 'uploaded')
                    || ($conversion->msclid && ($results['microsoft']['status'] ?? '') !== 'uploaded');
                $conversion->update(['upload_results' => $results, 'upload_status' => $remaining ? 'pending' : 'uploaded_all']);
            } catch (\Throwable $e) {
                report($e);
                $this->chargeAttempt(collect([$conversion]), $platform, $e->getMessage());
            }
        }

        return $conversions->count();
    }

    protected function uploadToMicrosoft(Customer $customer, $conversions): int
    {
        try {
            if (! $customer->microsoft_ads_account_id) {
                Log::warning('UploadOfflineConversions: Customer has no Microsoft Ads account', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'microsoft', 'Customer has no Microsoft Ads account id');
            }

            $msService = new MicrosoftConversionTrackingService($customer);
            $uetTagId = $customer->microsoft_uet_tag_id ?? $msService->resolveUetTagId();

            if (! $uetTagId) {
                Log::warning('UploadOfflineConversions: Could not resolve UET tag ID', ['customer_id' => $customer->id]);

                return $this->chargeAttempt($conversions, 'microsoft', 'Could not resolve a UET tag id');
            }

            $settled = 0;

            // One ApplyOfflineConversions call per conversion.
            //
            // This used to call createEventConversionGoal() per row, which
            // defines a brand new conversion *goal* rather than uploading a
            // conversion — so a busy account accumulated a goal per lead and
            // not one conversion was ever recorded against a click.
            foreach ($conversions as $conversion) {
                $results = $conversion->upload_results ?? [];

                // Microsoft attributes an offline conversion by click id; there
                // is nothing to attach it to without one.
                if (empty($conversion->msclid)) {
                    continue;
                }

                $settled++;

                try {
                    $result = $msService->applyOfflineConversion(
                        msclid: $conversion->msclid,
                        goalName: $conversion->conversion_name ?? 'Offline Conversion',
                        conversionTime: $conversion->conversion_time ?? $conversion->created_at ?? now(),
                        value: (float) $conversion->conversion_value,
                        currencyCode: $conversion->currency_code ?? 'USD',
                    );

                    if ($result) {
                        $results['microsoft'] = ['status' => 'uploaded', 'uploaded_at' => now()->toDateTimeString()];
                        $allDone = empty($conversion->gclid) && empty($conversion->fbclid);
                        $conversion->update([
                            'upload_status' => $allDone ? 'uploaded_all' : 'uploaded_microsoft',
                            'upload_results' => $results,
                        ]);
                    } else {
                        $results['microsoft'] = ['status' => 'failed', 'attempted_at' => now()->toDateTimeString()];
                        $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $results['microsoft'] = ['status' => 'failed', 'error' => $e->getMessage()];
                    $conversion->update(['upload_status' => 'failed', 'upload_results' => $results, 'upload_attempts' => $conversion->upload_attempts + 1]);
                }
            }

            Log::info('UploadOfflineConversions: Microsoft upload complete', [
                'customer_id' => $customer->id,
                'count' => $conversions->count(),
            ]);

            return $settled;
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::error('UploadOfflineConversions: Microsoft upload failed', ['error' => $e->getMessage()]);

            return $this->chargeAttempt($conversions, 'microsoft', $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UploadOfflineConversions failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
