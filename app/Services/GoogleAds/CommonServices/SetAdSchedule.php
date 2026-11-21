<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Common\AdScheduleInfo;
use Google\Ads\GoogleAds\V26\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V26\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class SetAdSchedule extends BaseGoogleAdsService
{
    /**
     * Set an ad schedule (dayparting) for a campaign.
     *
     * @param  int  $dayOfWeek  DayOfWeekEnum value (MONDAY=2 through SUNDAY=8)
     * @param  int  $startHour  0-23
     * @param  int  $startMinute  MinuteOfHourEnum (ZERO=2, FIFTEEN=3, THIRTY=4, FORTY_FIVE=5)
     * @param  int  $endHour  0-24 (24 = end of day)
     * @param  int  $endMinute  MinuteOfHourEnum (ZERO=2, FIFTEEN=3, THIRTY=4, FORTY_FIVE=5)
     * @param  float  $bidModifier  1.0=no change, 1.2=+20%, 0.8=-20%
     * @return string|null Resource name of the created criterion
     */
    public function __invoke(
        string $customerId,
        string $campaignResourceName,
        int $dayOfWeek,
        int $startHour,
        int $startMinute,
        int $endHour,
        int $endMinute,
        float $bidModifier = 1.0
    ): ?string {
        $campaign = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
        if ($campaign && ! CampaignSpendGuardrails::permitsBidModifier($campaign, $bidModifier)) {
            return null;
        }
        $this->ensureClient();

        $adSchedule = new AdScheduleInfo([
            'day_of_week' => $dayOfWeek,
            'start_hour' => $startHour,
            'start_minute' => $startMinute,
            'end_hour' => $endHour,
            'end_minute' => $endMinute,
        ]);

        $campaignCriterion = new CampaignCriterion([
            'campaign' => $campaignResourceName,
            'ad_schedule' => $adSchedule,
            'bid_modifier' => $bidModifier,
        ]);

        $operation = new CampaignCriterionOperation;
        $operation->setCreate($campaignCriterion);

        try {
            $response = $this->client->getCampaignCriterionServiceClient()->mutateCampaignCriteria(
                new MutateCampaignCriteriaRequest([
                    'validate_only' => $this->dryRun,
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Set ad schedule for day {$dayOfWeek} ({$startHour}:{$startMinute}-{$endHour}:{$endMinute}): {$resourceName}");

            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('Failed to set ad schedule: '.$e->getMessage());

            return null;
        }
    }

    /** Read only the actual enabled intervals; an empty result means all-day delivery. */
    public function enabledIntervals(string $customerId, string $campaign): array
    {
        if (! preg_match('~^customers/(\d+)/campaigns/\d+$~D', $campaign, $match) || $match[1] !== $customerId) {
            return [];
        }
        $this->ensureClient();
        $rows = $this->client->getGoogleAdsServiceClient()->search(new SearchGoogleAdsRequest([
            'customer_id' => $customerId,
            'query' => "SELECT campaign_criterion.resource_name, campaign_criterion.bid_modifier, campaign_criterion.ad_schedule.day_of_week, campaign_criterion.ad_schedule.start_hour, campaign_criterion.ad_schedule.end_hour, campaign_criterion.ad_schedule.start_minute, campaign_criterion.ad_schedule.end_minute FROM campaign_criterion WHERE campaign.resource_name = '{$campaign}' AND campaign_criterion.type = 'AD_SCHEDULE' AND campaign_criterion.status = 'ENABLED'",
        ]));
        $intervals = [];
        foreach ($rows->iterateAllElements() as $row) {
            $criterion = $row->getCampaignCriterion();
            $schedule = $criterion->getAdSchedule();
            if ($schedule && $schedule->getStartMinute() === 2 && $schedule->getEndMinute() === 2
                && $schedule->getEndHour() === $schedule->getStartHour() + 1) {
                $intervals[$criterion->getResourceName()] = ['hour' => $schedule->getStartHour(), 'modifier' => $criterion->getBidModifier()];
            }
        }

        return $intervals;
    }

    /** Caller selected an existing one-hour interval from enabledIntervals. Only its bid can change. */
    public function updateExistingModifier(string $customerId, string $campaign, string $resource, float $modifier): bool
    {
        $local = CampaignSpendGuardrails::forGoogleResource($customerId, $campaign);
        if (! $local || ! CampaignSpendGuardrails::permitsBidModifier($local, $modifier) || $this->dryRun) {
            return false;
        }
        $this->ensureClient();
        $operation = new CampaignCriterionOperation([
            'update' => new CampaignCriterion(['resource_name' => $resource, 'bid_modifier' => $modifier]),
            'update_mask' => new FieldMask(['paths' => ['bid_modifier']]),
        ]);
        $this->client->getCampaignCriterionServiceClient()->mutateCampaignCriteria(
            new MutateCampaignCriteriaRequest(['validate_only' => $this->dryRun, 'customer_id' => $customerId, 'operations' => [$operation]]));
        $actual = $this->enabledIntervals($customerId, $campaign)[$resource]['modifier'] ?? null;

        return $actual !== null && abs($actual - $modifier) < 0.0001;
    }

    /**
     * Set business hours schedule (Mon-Fri 9am-5pm) with optional bid modifier.
     */
    public function setBusinessHours(string $customerId, string $campaignResourceName, float $bidModifier = 1.2): array
    {
        $results = [];
        // MONDAY=2 through FRIDAY=6, MinuteOfHour::ZERO=2
        for ($day = 2; $day <= 6; $day++) {
            $results[] = $this($customerId, $campaignResourceName, $day, 9, 2, 17, 2, $bidModifier);
        }

        return $results;
    }
}
