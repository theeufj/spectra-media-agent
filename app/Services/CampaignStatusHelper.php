<?php

namespace App\Services;

use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use App\Models\Setting;

class CampaignStatusHelper
{
    /**
     * Check if the application is in campaign testing mode.
     * Reads from database Setting, falls back to config/env.
     *
     * @return bool
     */
    public static function isTestingMode(): bool
    {
        // Read from database Setting first
        $setting = Setting::where('key', 'campaign_testing_mode')->first();
        
        if ($setting) {
            return $setting->value === '1' || $setting->value === 'true';
        }
        
        // Fallback to config/env default
        return config('campaigns.testing_mode_default', false);
    }

    /**
     * Get the appropriate Google Ads campaign status based on testing mode.
     *
     * @param string|null $intendedStatus The intended status ('ENABLED', 'PAUSED'). Defaults to config.
     * @return int The Google Ads CampaignStatus enum value
     */
    public static function getGoogleAdsStatus(?string $intendedStatus = null): int
    {
        // If testing mode is enabled, always return PAUSED
        if (self::isTestingMode()) {
            return CampaignStatus::PAUSED;
        }

        // Use the intended status or fall back to config default
        $status = $intendedStatus ?? config('campaigns.default_status', 'ENABLED');

        return match (strtoupper($status)) {
            'ENABLED' => CampaignStatus::ENABLED,
            'PAUSED' => CampaignStatus::PAUSED,
            'REMOVED' => CampaignStatus::REMOVED,
            default => CampaignStatus::ENABLED,
        };
    }

    /**
     * Get the appropriate Facebook Ads campaign status based on testing mode.
     *
     * @param string|null $intendedStatus The intended status ('ACTIVE', 'PAUSED'). Defaults to config.
     * @return string The Facebook Ads status string
     */
    public static function getFacebookAdsStatus(?string $intendedStatus = null): string
    {
        // If testing mode is enabled, always return PAUSED
        if (self::isTestingMode()) {
            return 'PAUSED';
        }

        // Use the intended status or fall back to config default
        $status = $intendedStatus ?? config('campaigns.default_status', 'ENABLED');

        // Map our internal status to Facebook's expected values
        return match (strtoupper($status)) {
            'ENABLED', 'ACTIVE' => 'ACTIVE',
            'PAUSED' => 'PAUSED',
            default => 'ACTIVE',
        };
    }

    /**
     * Get a human-readable description of the current mode.
     *
     * @return string
     */
    public static function getModeDescription(): string
    {
        if (self::isTestingMode()) {
            return 'Testing Mode (all campaigns created as PAUSED)';
        }

        $defaultStatus = config('campaigns.default_status', 'ENABLED');
        return "Production Mode (campaigns created as {$defaultStatus})";
    }
}
