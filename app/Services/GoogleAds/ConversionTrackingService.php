<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

class ConversionTrackingService extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function isConversionTrackingSetUp(string $customerId): bool
    {
        $customerId = $this->validatedCustomerId($customerId);
        $query = "SELECT conversion_action.id, conversion_action.status FROM conversion_action WHERE conversion_action.status = 'ENABLED'";

        foreach ($this->readRows($customerId, $query) as $row) {
            $action = $row->getConversionAction();
            if ($action && $action->getStatus() === ConversionActionStatus::ENABLED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fresh account activity, not proof that a particular conversion action works.
     * API failures propagate: unavailable evidence must never become zero activity.
     *
     * @return array{period:string, conversions:float, all_conversions:float, clicks:int, impressions:int}
     */
    public function getAccountActivityLast30Days(string $customerId): array
    {
        $customerId = $this->validatedCustomerId($customerId);
        $query = 'SELECT metrics.conversions, metrics.all_conversions, metrics.clicks, metrics.impressions FROM customer WHERE segments.date DURING LAST_30_DAYS';
        $activity = ['period' => 'LAST_30_DAYS', 'conversions' => 0.0, 'all_conversions' => 0.0, 'clicks' => 0, 'impressions' => 0];

        foreach ($this->readRows($customerId, $query) as $row) {
            $metrics = $row->getMetrics();
            if (! $metrics) {
                throw new \UnexpectedValueException('Google returned an account activity row without metrics.');
            }
            $activity['conversions'] += $metrics->getConversions();
            $activity['all_conversions'] += $metrics->getAllConversions();
            $activity['clicks'] += (int) $metrics->getClicks();
            $activity['impressions'] += (int) $metrics->getImpressions();
        }

        return $activity;
    }

    /** Integer eligibility count for existing bidding callers; health checks use the full activity. */
    public function getConversionCountLast30Days(string $customerId): int
    {
        return (int) floor($this->getAccountActivityLast30Days($customerId)['conversions']);
    }

    /**
     * Attribution for the exact deployed campaign and intended action, never another account goal.
     *
     * @return array{period:string, conversions:float, all_conversions:float}
     */
    public function getCampaignActionConversionsLast30Days(string $customerId, string $campaignResource, string $actionResource): array
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->validateResource($customerId, $campaignResource, 'campaigns');
        $this->validateResource($customerId, $actionResource, 'conversionActions');
        $query = "SELECT segments.conversion_action, metrics.conversions, metrics.all_conversions FROM campaign WHERE campaign.resource_name = '{$campaignResource}' AND segments.conversion_action = '{$actionResource}' AND segments.date DURING LAST_30_DAYS";
        $activity = ['period' => 'LAST_30_DAYS', 'conversions' => 0.0, 'all_conversions' => 0.0];

        foreach ($this->readRows($customerId, $query) as $row) {
            $metrics = $row->getMetrics();
            if (! $metrics) {
                throw new \UnexpectedValueException('Google returned a campaign conversion row without metrics.');
            }
            $activity['conversions'] += $metrics->getConversions();
            $activity['all_conversions'] += $metrics->getAllConversions();
        }

        return $activity;
    }

    /** Read status independently of metrics: campaigns with no traffic still have a status. */
    public function getCampaignStatus(string $customerId, string $campaignResource): ?string
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->validateResource($customerId, $campaignResource, 'campaigns');
        $query = "SELECT campaign.status FROM campaign WHERE campaign.resource_name = '{$campaignResource}'";

        foreach ($this->readRows($customerId, $query) as $row) {
            return match ($row->getCampaign()?->getStatus()) {
                CampaignStatus::ENABLED => 'ENABLED',
                CampaignStatus::PAUSED => 'PAUSED',
                CampaignStatus::REMOVED => 'REMOVED',
                default => null,
            };
        }

        return null;
    }

    private function validatedCustomerId(string $customerId): string
    {
        $customerId = str_replace('-', '', trim($customerId));
        if ($customerId === '' || ! ctype_digit($customerId)
            || $customerId !== $this->customer?->cleanGoogleCustomerId()) {
            throw new \LogicException('Conversion tracking evidence must belong to the current customer Google Ads account.');
        }

        return $customerId;
    }

    private function validateResource(string $customerId, string $resource, string $collection): void
    {
        if (! preg_match('~^customers/'.preg_quote($customerId, '~').'/'.$collection.'/\d+$~', $resource)) {
            throw new \LogicException('Conversion tracking resources must belong to the current customer Google Ads account.');
        }
    }

    /** @return iterable<GoogleAdsRow> */
    protected function readRows(string $customerId, string $query): iterable
    {
        $this->ensureClient();

        return $this->searchQuery($customerId, $query)->iterateAllElements();
    }
}
