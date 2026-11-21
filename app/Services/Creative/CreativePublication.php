<?php

namespace App\Services\Creative;

use App\Enums\CampaignStatus;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Campaigns\CampaignSpendGuardrails;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreativePublication
{
    public static function channel(Strategy $strategy): ?string
    {
        $channel = CampaignResourceInventory::platform($strategy);

        return $channel === 'facebook' || ($channel === 'google' && $strategy->campaign_type === 'performance_max') ? $channel : null;
    }

    public static function begin(Strategy $strategy, string $fingerprint, array $previous): void
    {
        if (($strategy->creative_publication['fingerprint'] ?? null) === $fingerprint) {
            return;
        }
        $previousState = $strategy->creative_publication ?? [];
        if ($previousState && ! in_array($previousState['phase'], ['published', 'failed', 'superseded'], true)) {
            throw new RuntimeException('A previous creative update is still awaiting platform review.');
        }
        $strategy->forceFill(['creative_publication' => [
            'fingerprint' => $fingerprint, 'channel' => self::channel($strategy), 'phase' => 'staging',
            'previous_approval' => $previous, 'previous_managed' => $previousState['replacement_links'] ?? [],
            'created_at' => now()->toIso8601String(),
        ]])->save();
    }

    public static function write(int $id, string $fingerprint, array $changes, array $strategyChanges = []): bool
    {
        return DB::transaction(function () use ($id, $fingerprint, $changes, $strategyChanges) {
            $locked = Strategy::lockForUpdate()->find($id);
            if (! $locked || ($locked->creative_publication['fingerprint'] ?? null) !== $fingerprint
                || ($locked->creative_approval['fingerprint'] ?? null) !== $fingerprint) {
                return false;
            }
            if (($changes['phase'] ?? null) === 'published') {
                $strategyChanges['creative_approval'] = [...$locked->creative_approval, 'published_at' => now()->toIso8601String()];
                if ($locked->launch_intent !== null) {
                    $strategyChanges['launch_intent'] = [...$locked->launch_intent, 'creative_fingerprint' => $fingerprint];
                }
                if ($locked->creative_revision !== null) {
                    $strategyChanges['creative_revision'] = [...$locked->creative_revision, 'status' => 'published'];
                }
            }
            $locked->forceFill($strategyChanges + ['creative_publication' => array_replace($locked->creative_publication, $changes, ['updated_at' => now()->toIso8601String()])])->save();

            return true;
        });
    }

    /** Every mutating step must still own exactly this reviewed budget, channel account and campaign. */
    public static function guard(Strategy $strategy, string $fingerprint): array
    {
        $current = $strategy->fresh(['campaign.customer']);
        $campaign = $current?->campaign;
        if (! $campaign || $campaign->status !== CampaignStatus::Active || $current->deployment_status !== 'active'
            || ($current->creative_publication['fingerprint'] ?? null) !== $fingerprint
            || ($current->creative_approval['fingerprint'] ?? null) !== $fingerprint || ! app(CreativeRelease::class)->isApproved($current)
            || ! $campaign->budget_confirmed_at || ! CampaignSpendGuardrails::canEnable($campaign)
            || ! CampaignSpendGuardrails::permitsDailyBudget($campaign, CampaignSpendGuardrails::moneyToMicros($current->daily_budget))
            || (($current->launch_intent['version'] ?? 1) >= 2
                ? ! app(\App\Services\Billing\LaunchFunding::class)->funded($current)
                : ! CampaignSpendGuardrails::hasLaunchFunding($campaign->customer))) {
            throw new RuntimeException('The approved release, funding or campaign delivery settings have changed.');
        }
        $entries = collect(CampaignResourceInventory::entries($campaign))->where('platform', self::channel($current))
            ->where('strategy_id', $current->id)->values();
        if ($entries->count() !== 1) {
            throw new RuntimeException('The ad account and campaign could not be verified for this release.');
        }

        return $entries->first();
    }
}
