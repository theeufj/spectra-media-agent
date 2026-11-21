<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;

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

    /** Read the exact enabled action and the provider's account type before sending an event. */
    public function outcomeDestination(string $customerId, string $resource): ?array
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->validateResource($customerId, $resource, 'conversionActions');
        $query = "SELECT conversion_action.resource_name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.tag_snippets, metrics.conversion_last_received_request_date_time FROM conversion_action WHERE conversion_action.resource_name = '{$resource}' AND conversion_action.status = 'ENABLED'";
        foreach ($this->readRows($customerId, $query) as $row) {
            $action = $row->getConversionAction();
            if ($action && $action->getResourceName() === $resource && $action->getStatus() === ConversionActionStatus::ENABLED) {
                $sendTo = null;
                foreach ($action->getTagSnippets() as $snippet) {
                    if (preg_match('/["\']send_to["\']\s*:\s*["\'](AW-\d+\/[a-zA-Z0-9_-]+)["\']/', $snippet->getEventSnippet(), $match)) {
                        $sendTo = $match[1];
                        break;
                    }
                }
                $zone = null;
                foreach ($this->readRows($customerId, 'SELECT customer.time_zone FROM customer LIMIT 1') as $account) {
                    $zone = $account->getCustomer()?->getTimeZone();
                }

                return ['time_zone' => $zone, 'resource' => $resource, 'last_tag_at' => $row->getMetrics()?->getConversionLastReceivedRequestDateTime(),
                    'send_to' => $sendTo, 'category' => \Google\Ads\GoogleAds\V26\Enums\ConversionActionCategoryEnum\ConversionActionCategory::name($action->getCategory()),
                    'type' => \Google\Ads\GoogleAds\V26\Enums\ConversionActionTypeEnum\ConversionActionType::name($action->getType())];
            }
        }

        return null;
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

    /** Exact primary action for a budget comparison over completed days in one account currency. */
    public function budgetActionTotals(string $account, string $campaign, string $action, string $goal, string $currency, string $from, string $to): ?array
    {
        $account = $this->validatedCustomerId($account);
        $this->validateResource($account, $campaign, 'campaigns');
        $this->validateResource($account, $action, 'conversionActions');
        $category = ['purchase' => 'PURCHASE', 'signup' => 'SIGNUP', 'lead' => 'SUBMIT_LEAD_FORM'][$goal] ?? null;
        if (! $category || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) || $from > $to) {
            return null;
        }
        $eligible = false;
        $query = "SELECT conversion_action.resource_name, conversion_action.value_settings.always_use_default_value FROM conversion_action WHERE conversion_action.resource_name = '{$action}' AND conversion_action.status = 'ENABLED' AND conversion_action.primary_for_goal = TRUE AND conversion_action.category = '{$category}'";
        foreach ($this->readRows($account, $query) as $row) {
            $actual = $row->getConversionAction();
            $eligible = $actual?->getResourceName() === $action && ($goal !== 'purchase'
                || ($actual->getValueSettings() && ! $actual->getValueSettings()->getAlwaysUseDefaultValue()));
        }
        if (! $eligible) {
            return null;
        }
        $count = 0.0;
        $value = 0.0;
        $query = 'SELECT customer.id, customer.currency_code, campaign.resource_name, segments.conversion_action, metrics.conversions, metrics.conversions_value '
            ."FROM campaign WHERE campaign.resource_name = '{$campaign}' AND segments.conversion_action = '{$action}' AND segments.date BETWEEN '{$from}' AND '{$to}'";
        foreach ($this->readRows($account, $query) as $row) {
            $metrics = $row->getMetrics();
            if (! $metrics || (string) $row->getCustomer()?->getId() !== $account || $row->getCustomer()?->getCurrencyCode() !== $currency
                || $row->getCampaign()?->getResourceName() !== $campaign || $row->getSegments()?->getConversionAction() !== $action) {
                return null;
            }
            $count += $metrics->getConversions();
            $value += $goal === 'purchase' ? $metrics->getConversionsValue() : 0;
        }

        return ['conversions' => $count, 'conversion_value' => $value];
    }

    /** Read valued purchase activity for one campaign and an unambiguous purchase action. */
    public function getPerformanceMaxPurchaseEvidence(string $customerId, string $resource, ?string $selectedAction = null): ?array
    {
        $customerId = $this->validatedCustomerId($customerId);
        $this->validateResource($customerId, $resource, 'campaigns');
        if ($selectedAction !== null) {
            $this->validateResource($customerId, $selectedAction, 'conversionActions');
        }
        $actions = [];
        $actionFilter = $selectedAction ? " AND conversion_action.resource_name = '{$selectedAction}'" : '';
        $query = 'SELECT conversion_action.resource_name, conversion_action.value_settings.always_use_default_value FROM conversion_action '
            ."WHERE conversion_action.category = 'PURCHASE' AND conversion_action.status = 'ENABLED'{$actionFilter}";
        foreach ($this->readRows($customerId, $query) as $row) {
            $action = $row->getConversionAction();
            if ($action && $action->getValueSettings() && ! $action->getValueSettings()->getAlwaysUseDefaultValue()) {
                $actions[] = $action->getResourceName();
            }
        }
        if (count($actions) !== 1) {
            return null;
        }
        $action = $actions[0];
        $this->validateResource($customerId, $action, 'conversionActions');
        $end = now($this->customer->timezone)->subDays(3)->startOfDay();
        $from = $end->copy()->subDays(27)->toDateString();
        $through = $end->toDateString();
        $query = 'SELECT segments.date, metrics.all_conversions_by_conversion_date, metrics.all_conversions_value_by_conversion_date '
            ."FROM campaign WHERE campaign.resource_name = '{$resource}' AND segments.conversion_action = '{$action}' "
            ."AND segments.date BETWEEN '{$from}' AND '{$through}'";
        $days = [];
        foreach ($this->readRows($customerId, $query) as $row) {
            $date = $row->getSegments()?->getDate();
            $metrics = $row->getMetrics();
            if (! $date || ! $metrics || $date < $from || $date > $through || isset($days[$date])) {
                return null;
            }
            $days[$date] = ['conversions' => $metrics->getAllConversionsByConversionDate(),
                'value' => $metrics->getAllConversionsValueByConversionDate()];
        }

        return ['action_resource' => $action, 'from' => $from, 'through' => $through, 'days' => $days];
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
