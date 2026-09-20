<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;

class CampaignStatusHelper
{
    /**
     * Check if the application is in campaign testing mode.
     * Reads from database Setting, falls back to config/env.
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
     * @param  string|null  $intendedStatus  The intended status ('ENABLED', 'PAUSED'). Defaults to config.
     * @return int The Google Ads CampaignStatus enum value
     */
    public static function getGoogleAdsStatus(?string $intendedStatus = null, ?Customer $customer = null): int
    {
        // Setup packages must start paused even if the build never reaches settlement.
        if ($customer?->service_type === 'setup_only') {
            return CampaignStatus::PAUSED;
        }

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
     * @param  string|null  $intendedStatus  The intended status ('ACTIVE', 'PAUSED'). Defaults to config.
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
     * Get the appropriate Microsoft Advertising campaign status based on testing mode.
     *
     * Microsoft's vocabulary is title-case Active/Paused, which maps one-for-one
     * onto Facebook's ACTIVE/PAUSED — so the testing-mode and
     * `campaigns.default_status` decision stays in getFacebookAdsStatus() rather
     * than growing a third copy of it. This exists because there was no Microsoft
     * entry point at all: callers either hand-rolled the mapping inline or
     * hardcoded 'Paused', which is how Microsoft campaigns deployed and stayed off.
     *
     * @param  string|null  $intendedStatus  The intended status ('ACTIVE', 'PAUSED'). Defaults to config.
     */
    public static function getMicrosoftAdsStatus(?string $intendedStatus = null): string
    {
        return self::getFacebookAdsStatus($intendedStatus) === 'PAUSED' ? 'Paused' : 'Active';
    }

    /**
     * Get the appropriate LinkedIn Ads campaign status based on testing mode.
     *
     * LinkedIn's campaign status vocabulary is the same ACTIVE/PAUSED strings
     * Facebook uses, so the decision is shared rather than duplicated.
     *
     * @param  string|null  $intendedStatus  The intended status ('ACTIVE', 'PAUSED'). Defaults to config.
     */
    public static function getLinkedInAdsStatus(?string $intendedStatus = null): string
    {
        return self::getFacebookAdsStatus($intendedStatus);
    }

    /**
     * Get a human-readable description of the current mode.
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
