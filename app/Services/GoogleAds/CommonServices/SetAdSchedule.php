<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Common\AdScheduleInfo;
use Google\Ads\GoogleAds\V22\Resources\CampaignCriterion;
use Google\Ads\GoogleAds\V22\Services\CampaignCriterionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignCriteriaRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\ApiCore\ApiException;

class SetAdSchedule extends BaseGoogleAdsService
{
    /**
     * Set an ad schedule (dayparting) for a campaign.
     *
     * @param string $customerId
     * @param string $campaignResourceName
     * @param int $dayOfWeek DayOfWeekEnum value (MONDAY=2 through SUNDAY=8)
     * @param int $startHour 0-23
     * @param int $startMinute MinuteOfHourEnum (ZERO=2, FIFTEEN=3, THIRTY=4, FORTY_FIVE=5)
     * @param int $endHour 0-24 (24 = end of day)
     * @param int $endMinute MinuteOfHourEnum (ZERO=2, FIFTEEN=3, THIRTY=4, FORTY_FIVE=5)
     * @param float $bidModifier 1.0=no change, 1.2=+20%, 0.8=-20%
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

        $operation = new CampaignCriterionOperation();
        $operation->setCreate($campaignCriterion);

        try {
            $response = $this->client->getCampaignCriterionServiceClient()->mutateCampaignCriteria(
                new MutateCampaignCriteriaRequest([
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $resourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Set ad schedule for day {$dayOfWeek} ({$startHour}:{$startMinute}-{$endHour}:{$endMinute}): {$resourceName}");
            return $resourceName;
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError("Failed to set ad schedule: " . $e->getMessage());
            return null;
        }
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
