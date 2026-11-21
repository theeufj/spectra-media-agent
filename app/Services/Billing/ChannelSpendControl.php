<?php

namespace App\Services\Billing;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Support\Facades\DB;

/** Suspend funded resources independently; only restore an exact, recorded billing pause. */
class ChannelSpendControl
{
    public function applies(Campaign $campaign): bool
    {
        $campaign->loadMissing('strategies');
        if ($campaign->strategies->contains(fn ($s) => ($s->launch_intent['version'] ?? 1) === 2)) {
            return true;
        }
        $entries = CampaignResourceInventory::entries($campaign);
        $platforms = array_column($entries, 'platform');

        return count($platforms) !== count(array_unique($platforms))
            || $campaign->customer?->isSelfFundedAds() && in_array('facebook', $platforms, true);
    }

    /** @return array{resumed:int,failed:int,skipped:int} */
    public function apply(Campaign $campaign, bool $resume): array
    {
        $counts = ['resumed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (CampaignResourceInventory::entries($campaign) as $entry) {
            try {
                $campaign->refresh()->load('customer', 'strategies');
                $customer = $campaign->customer;
                $strategy = $campaign->strategies->firstWhere('id', $entry['strategy_id']);
                $binding = $strategy?->launch_intent['funding'] ?? [];
                $scoped = ($strategy?->launch_intent['version'] ?? 1) === 2;
                $managed = $scoped ? ($binding['payer'] ?? null) === 'agency' && ($binding['account'] ?? null) === $entry['account_id']
                    : $customer->service_type !== 'setup_only' && ($entry['platform'] !== 'google' || ! $customer->isSelfFundedAds());
                if (! $managed) {
                    $counts['skipped']++;

                    continue;
                }
                $key = implode(':', [$entry['platform'], $entry['account_id'], $entry['resource_id']]);
                $hold = $campaign->search_delivery_state['billing_resource_pauses'][$key] ?? null;
                if ($resume && (! $hold || $campaign->status !== CampaignStatus::Active || ! CampaignSpendGuardrails::canEnable($campaign)
                    || CampaignSpendGuardrails::automaticChangesSuspended($campaign) || ! $customer->hasPaidDeploymentAccess()
                    || ($scoped && ! app(CampaignLaunchIntent::class)->canPublish($strategy)))) {
                    $counts['skipped']++;

                    continue;
                }
                $status = $this->status($campaign, $entry);
                if ($status === 'other' || (! $resume && ! $hold && $status !== 'enabled')) {
                    $counts['skipped']++;

                    continue;
                }
                if (! $resume && $hold && $status === 'paused') {
                    $this->save($campaign, $key, 'paused');

                    continue;
                }
                if ($resume && $status === 'enabled') {
                    $this->save($campaign, $key, null);
                    $counts['resumed']++;

                    continue;
                }
                if (! $resume) {
                    $this->save($campaign, $key, 'pausing');
                }
                $google = $entry['platform'] === 'google';
                if ($google) {
                    $service = app(UpdateCampaignStatus::class, ['customer' => $customer]);
                    $result = $resume ? $service->enable($entry['account_id'], $entry['resource_name'])
                        : $service->pause($entry['account_id'], $entry['resource_name']);
                    $ok = $result['success'] ?? false;
                } else {
                    $ok = app(CampaignService::class, ['customer' => $customer])->updateCampaign($entry['resource_id'], ['status' => $resume ? 'ACTIVE' : 'PAUSED']);
                }
                if (! $ok || $this->status($campaign, $entry) !== ($resume ? 'enabled' : 'paused')) {
                    throw new \RuntimeException('The ad platform did not confirm the billing status change.');
                }
                $this->save($campaign, $key, $resume ? null : 'paused');
                if ($resume) {
                    $counts['resumed']++;
                }
            } catch (\Throwable $e) {
                report($e);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    private function status(Campaign $campaign, array $entry): string
    {
        if ($entry['platform'] === 'google') {
            $result = app(GetCampaignStatus::class, ['customer' => $campaign->customer])($entry['account_id'], $entry['resource_name']);
            if (! isset($result['status'])) {
                throw new \RuntimeException('Could not verify the funded Google campaign status.');
            }

            return match ($result['status']) {
                GoogleStatus::ENABLED => 'enabled', GoogleStatus::PAUSED => 'paused', default => 'other',
            };
        }
        $result = app(CampaignService::class, ['customer' => $campaign->customer])->getCampaign($entry['resource_id']);
        if ((string) ($result['id'] ?? '') !== $entry['resource_id'] || (string) ($result['account_id'] ?? '') !== $entry['account_id']) {
            throw new \RuntimeException('Could not verify the funded Meta campaign account and status.');
        }

        return match ($result['status'] ?? null) {
            'ACTIVE' => 'enabled', 'PAUSED' => 'paused', default => 'other',
        };
    }

    private function save(Campaign $campaign, string $key, ?string $status): void
    {
        DB::transaction(function () use ($campaign, $key, $status) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $state = $locked->search_delivery_state ?? [];
            if ($status) {
                $state['billing_resource_pauses'][$key] = ['status' => $status, 'checked_at' => now()->toIso8601String()];
            } else {
                unset($state['billing_resource_pauses'][$key]);
            }
            $locked->update(['search_delivery_state' => $state]);
        });
    }
}
