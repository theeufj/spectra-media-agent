<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetPerformanceBySegment;
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use Google\Ads\GoogleAds\V22\Enums\DeviceEnum\Device;
use Google\Ads\GoogleAds\V22\Enums\DayOfWeekEnum\DayOfWeek;
use Google\Ads\GoogleAds\V22\Enums\MinuteOfHourEnum\MinuteOfHour;
use Illuminate\Support\Facades\Log;

/**
 * Analyses campaign performance by device and time-of-day and applies
 * bid modifiers to concentrate spend where it converts most efficiently.
 *
 * Device modifiers: applied when a device CPA diverges >20% from average
 *   and has >50 conversions or >200 clicks.
 *
 * Dayparting modifiers: groups consecutive high/low-performing hours into
 *   ad schedule blocks with appropriate modifiers.
 *
 * All modifiers are clamped to Google Ads limits: -90% (0.1) to +900% (10.0).
 */
class BidAdjustmentAgent
{
    private const DEVICE_MIN_CONVERSIONS = 50;
    private const DEVICE_MIN_CLICKS      = 200;
    private const DEVICE_DIVERGENCE      = 0.20;   // 20% from average CPA triggers modifier
    private const HOUR_MIN_CLICKS        = 30;
    private const HOUR_HIGH_THRESHOLD    = 0.70;   // CPA < 70% of avg → boost
    private const HOUR_LOW_THRESHOLD     = 1.50;   // CPA > 150% of avg → reduce
    private const MODIFIER_CLAMP_MIN     = 0.10;
    private const MODIFIER_CLAMP_MAX     = 10.0;

    // MinuteOfHour enum: ZERO=2
    private const MINUTE_ZERO = 2;

    public function optimize(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $customerId       = $customer->cleanGoogleCustomerId();
        $campaignResource = $campaign->google_ads_campaign_id;

        $adjustments = [];
        $errors      = [];

        $segmentService = new GetPerformanceBySegment($customer);

        // --- Device bid modifiers ---
        $deviceData = $segmentService->byDevice($customerId, $campaignResource);
        $adjustments = array_merge($adjustments, $this->applyDeviceModifiers($customer, $customerId, $campaignResource, $deviceData, $errors));

        // --- Dayparting bid modifiers ---
        $hourData = $segmentService->byHour($customerId, $campaignResource);
        $adjustments = array_merge($adjustments, $this->applyDaypartingModifiers($customer, $customerId, $campaignResource, $hourData, $errors));

        if (!empty($adjustments)) {
            AgentActivity::record(
                'bidding',
                'bid_adjustments_applied',
                'Applied ' . count($adjustments) . ' bid modifier(s) for "' . $campaign->name . '"',
                $campaign->customer_id,
                $campaign->id,
                ['adjustments' => $adjustments, 'errors' => $errors]
            );
        }

        return ['adjustments' => $adjustments, 'errors' => $errors];
    }

    private function applyDeviceModifiers(object $customer, string $customerId, string $campaignResource, array $deviceData, array &$errors): array
    {
        if (empty($deviceData)) {
            return [];
        }

        $totalCost        = array_sum(array_column($deviceData, 'cost_micros'));
        $totalConversions = array_sum(array_column($deviceData, 'conversions'));

        if ($totalConversions < 1) {
            return [];
        }

        $avgCpaMicros = $totalCost / $totalConversions;
        $service      = new SetDeviceBidAdjustment($customer);
        $adjustments  = [];

        // Device enum values: MOBILE=2, DESKTOP=4, TABLET=6
        $deviceMap = [Device::MOBILE => 'Mobile', Device::DESKTOP => 'Desktop', Device::TABLET => 'Tablet'];

        foreach ($deviceData as $deviceEnum => $data) {
            $qualifies = ($data['conversions'] >= self::DEVICE_MIN_CONVERSIONS)
                || ($data['clicks'] >= self::DEVICE_MIN_CLICKS && $data['conversions'] >= 5);

            if (!$qualifies || $data['conversions'] < 1) {
                continue;
            }

            $deviceCpa = $data['cost_micros'] / $data['conversions'];
            $ratio     = $deviceCpa / $avgCpaMicros;

            // Only adjust if diverges >20%
            if (abs(1 - $ratio) <= self::DEVICE_DIVERGENCE) {
                continue;
            }

            // Modifier = inverse ratio, clamped
            $modifier = min(self::MODIFIER_CLAMP_MAX, max(self::MODIFIER_CLAMP_MIN, 1 / $ratio));
            $modifier = round($modifier, 2);

            $result = ($service)($customerId, $campaignResource, $deviceEnum, $modifier);

            if ($result) {
                $label = $deviceMap[$deviceEnum] ?? $deviceEnum;
                $adjustments[] = [
                    'type'     => 'device',
                    'device'   => $label,
                    'modifier' => $modifier,
                    'cpa_vs_avg' => round($ratio, 2),
                ];

                Log::info("BidAdjustmentAgent: Device modifier {$modifier}x for {$label}", [
                    'campaign_resource' => $campaignResource,
                ]);
            } else {
                $errors[] = "Failed to set device modifier for device {$deviceEnum}";
            }
        }

        return $adjustments;
    }

    private function applyDaypartingModifiers(object $customer, string $customerId, string $campaignResource, array $hourData, array &$errors): array
    {
        if (empty($hourData)) {
            return [];
        }

        $totalCost        = array_sum(array_column($hourData, 'cost_micros'));
        $totalConversions = array_sum(array_column($hourData, 'conversions'));

        if ($totalConversions < 1) {
            return [];
        }

        $avgCpaMicros = $totalCost / $totalConversions;
        $service      = new SetAdSchedule($customer);
        $adjustments  = [];

        // Group hours into "high", "low", "normal" performance blocks.
        // Apply to all 7 days (Sunday=8 … Saturday=7, Monday=2 … Sunday=8 in enum).
        // We apply day-agnostic hourly schedules for simplicity.
        $dayEnums = [
            DayOfWeek::MONDAY, DayOfWeek::TUESDAY, DayOfWeek::WEDNESDAY,
            DayOfWeek::THURSDAY, DayOfWeek::FRIDAY, DayOfWeek::SATURDAY, DayOfWeek::SUNDAY,
        ];

        foreach ($hourData as $hour => $data) {
            if ($data['clicks'] < self::HOUR_MIN_CLICKS || $data['conversions'] < 1) {
                continue;
            }

            $hourCpa = $data['cost_micros'] / $data['conversions'];
            $ratio   = $hourCpa / $avgCpaMicros;

            if ($ratio < self::HOUR_HIGH_THRESHOLD) {
                $modifier = min(self::MODIFIER_CLAMP_MAX, round(1 / $ratio, 2)); // boost
            } elseif ($ratio > self::HOUR_LOW_THRESHOLD) {
                $modifier = max(self::MODIFIER_CLAMP_MIN, round(1 / $ratio, 2)); // reduce
            } else {
                continue;
            }

            $endHour = min(24, $hour + 1);

            foreach ($dayEnums as $dayEnum) {
                ($service)(
                    $customerId,
                    $campaignResource,
                    $dayEnum,
                    $hour,
                    self::MINUTE_ZERO,
                    $endHour,
                    self::MINUTE_ZERO,
                    $modifier
                );
            }

            $adjustments[] = [
                'type'       => 'daypart',
                'hour'       => $hour,
                'modifier'   => $modifier,
                'cpa_vs_avg' => round($ratio, 2),
            ];
        }

        return $adjustments;
    }
}
