<?php

namespace App\Services\FacebookAds;

use App\Models\Campaign;
use App\Models\Strategy;

class FacebookCampaignMetrics
{
    public static function strategy(Campaign $campaign): ?Strategy
    {
        return $campaign->strategies()
            ->where(fn ($q) => $q->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])
                ->orWhereRaw('LOWER(platform) LIKE ?', ['%meta%'])
                ->orWhereRaw('LOWER(platform) LIKE ?', ['%instagram%']))
            ->where(fn ($q) => $q->where('facebook_campaign_id', $campaign->facebook_ads_campaign_id)
                ->orWhereNull('facebook_campaign_id'))
            ->orderByRaw('CASE WHEN facebook_campaign_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')->first();
    }

    public static function actionType(?Strategy $strategy): string
    {
        $goal = $strategy?->conversion_goals['primary_goal'] ?? '';
        $goal = is_string($goal) ? preg_replace('/[^a-z]/', '', strtolower($goal)) : '';

        return match ($goal) {
            'signup', 'signups', 'websitesignup', 'completeregistration' => 'complete_registration',
            'lead', 'leads', 'leadgeneration', 'generateleads' => 'lead',
            default => 'purchase',
        };
    }

    public static function conversions(?array $actions, string $actionType): float
    {
        // Insights can return both the pixel event and a rollup for the same action.
        foreach (['offsite_conversion.fb_pixel_'.$actionType, $actionType, 'omni_'.$actionType] as $name) {
            foreach ($actions ?? [] as $action) {
                if (($action['action_type'] ?? null) === $name) {
                    return (float) ($action['value'] ?? 0);
                }
            }
        }

        return 0;
    }
}
