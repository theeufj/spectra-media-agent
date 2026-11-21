<?php

namespace App\Services\Deployment;

use App\Models\Strategy;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Commit the lookup identity before sending a create. A lost reply must never authorize another create. */
class SubmittedCampaign
{
    public static function name(Strategy $strategy, string $platform, string $account, string $name): string
    {
        $intent = $strategy->launch_intent;
        if (($intent['version'] ?? 1) !== 2) {
            return $name;
        }
        $account = preg_replace('/^act_/', '', $account);

        return DB::transaction(function () use ($strategy, $intent, $platform, $account, $name) {
            $locked = Strategy::lockForUpdate()->findOrFail($strategy->id);
            $current = $locked->launch_intent;
            if (! Str::isUuid($intent['id']) || $intent['id'] !== ($current['id'] ?? null)
                || ($current['funding']['account'] ?? null) !== $account || isset($current['campaign_create'])) {
                throw new RuntimeException('The channel campaign must be reconciled with the existing approval before creation.');
            }
            $marker = 'STS '.$intent['id'].' ';
            $current['campaign_create'] = ['platform' => $platform, 'account' => $account, 'requested_at' => now()->toIso8601String()];
            $locked->forceFill(['launch_intent' => $current])->save();
            $strategy->setAttribute('launch_intent', $current);
            $strategy->syncOriginalAttribute('launch_intent');

            return $marker.mb_substr($name, 0, 75);
        });
    }

    /** Re-enter the normal funding and approval gates only for a confirmed, paused, empty remote campaign. */
    public function reconcile(Strategy $strategy): void
    {
        $intent = $strategy->launch_intent ?? [];
        $marker = $intent['campaign_create'] ?? [];
        $customer = $strategy->campaign?->customer;
        if (! $customer || ! Str::isUuid($intent['id'] ?? '') || ! isset($marker['account'], $marker['platform'])
            || ! ctype_digit($marker['account'])) {
            return;
        }
        $google = $marker['platform'] === 'google';
        $account = $google ? $customer->cleanGoogleCustomerId() : preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
        if ($account !== $marker['account'] || ! in_array($marker['platform'], ['google', 'facebook'], true)) {
            return;
        }
        $provider = $google ? app(ReadCampaignConfiguration::class, ['customer' => $customer]) : app(CampaignService::class, ['customer' => $customer]);
        $resource = $provider->findEmptyPausedLaunch($account, $intent['id']);
        if ($resource === null) {
            return;
        }
        DB::transaction(function () use ($strategy, $intent, $resource, $google) {
            $locked = Strategy::lockForUpdate()->findOrFail($strategy->id);
            $current = $locked->launch_intent;
            $existing = $google ? $locked->google_ads_campaign_id : $locked->facebook_campaign_id;
            if (($current['id'] ?? null) !== $intent['id'] || $locked->updated_at->gt(now()->subMinutes(35))
                || ! in_array($locked->deployment_status, ['queued', 'deploying', 'failed'], true)
                || $existing && $existing !== $resource || ($current['empty_campaign_resumes'] ?? 0) >= 2) {
                return;
            }
            if ($google) {
                $locked->recordGoogleCampaignId($resource);
            } else {
                $locked->recordFacebookCampaignId($resource);
            }
            unset($current['started_at']);
            $current['empty_campaign_resumes'] = ($current['empty_campaign_resumes'] ?? 0) + 1;
            $locked->forceFill(['launch_intent' => $current, 'deployment_status' => CampaignLaunchIntent::WAITING, 'deployment_error' => null])->save();
        });
    }
}
