<?php

namespace App\Services\MicrosoftAds;

use Illuminate\Support\Facades\Log;

class ConversionTrackingService extends BaseMicrosoftAdsService
{
    /**
     * Check if conversion tracking is set up for the account.
     */
    public function isConversionTrackingSetUp(): bool
    {
        $goals = $this->getConversionGoals();

        return !empty($goals);
    }

    /**
     * Get all conversion goals (UET-based) for the account.
     */
    public function getConversionGoals(): array
    {
        $result = $this->apiCallWithRetry('GetConversionGoalsByIds', [
            'ConversionGoalIds' => null,
            'ConversionGoalTypes' => 'Url Event Duration PagesViewedPerVisit InStoreTransaction',
            'ReturnAdditionalFields' => 'ViewThroughConversionWindowInMinutes',
        ]);

        if ($result && isset($result['ConversionGoals']['ConversionGoal'])) {
            $goals = $result['ConversionGoals']['ConversionGoal'];

            // Normalise single-item responses to array
            return isset($goals['Id']) ? [$goals] : $goals;
        }

        return [];
    }

    /**
     * Create a URL conversion goal (e.g. thank-you page visit).
     */
    public function createUrlConversionGoal(array $params): ?array
    {
        $goal = [
            'Name' => $params['name'],
            'Type' => 'Url',
            'ConversionWindowInMinutes' => $params['conversion_window_minutes'] ?? 43200, // 30 days
            'CountType' => $params['count_type'] ?? 'All', // All or Unique
            'Revenue' => [
                'Type' => $params['revenue_type'] ?? 'NoValue',
                'Value' => $params['revenue_value'] ?? 0,
                'CurrencyCode' => $params['currency_code'] ?? null,
            ],
            'Status' => 'Active',
            'Scope' => 'Account',
            'TagId' => $params['uet_tag_id'] ?? null,
            'UrlExpression' => $params['url_contains'] ?? null,
            'UrlOperator' => $params['url_operator'] ?? 'Contains',
        ];

        $result = $this->apiCallWithRetry('AddConversionGoals', [
            'ConversionGoals' => ['ConversionGoal' => [$goal]],
        ]);

        if ($result && isset($result['ConversionGoalIds'])) {
            Log::info('Microsoft Ads: Created URL conversion goal', [
                'name' => $params['name'],
                'ids' => $result['ConversionGoalIds'],
            ]);
            return $result;
        }

        return null;
    }

    /**
     * Create an event conversion goal (custom events via UET tag).
     */
    public function createEventConversionGoal(array $params): ?array
    {
        $goal = [
            'Name' => $params['name'],
            'Type' => 'Event',
            'ConversionWindowInMinutes' => $params['conversion_window_minutes'] ?? 43200,
            'CountType' => $params['count_type'] ?? 'All',
            'Revenue' => [
                'Type' => $params['revenue_type'] ?? 'NoValue',
                'Value' => $params['revenue_value'] ?? 0,
                'CurrencyCode' => $params['currency_code'] ?? null,
            ],
            'Status' => 'Active',
            'Scope' => 'Account',
            'TagId' => $params['uet_tag_id'] ?? null,
            'ActionExpression' => $params['action_expression'] ?? null,
            'ActionOperator' => $params['action_operator'] ?? 'Equals',
            'CategoryExpression' => $params['category_expression'] ?? null,
            'CategoryOperator' => $params['category_operator'] ?? 'Equals',
            'LabelExpression' => $params['label_expression'] ?? null,
            'LabelOperator' => $params['label_operator'] ?? 'Equals',
            'Value' => $params['event_value'] ?? null,
            'ValueOperator' => $params['value_operator'] ?? 'Equals',
        ];

        $result = $this->apiCallWithRetry('AddConversionGoals', [
            'ConversionGoals' => ['ConversionGoal' => [$goal]],
        ]);

        if ($result && isset($result['ConversionGoalIds'])) {
            Log::info('Microsoft Ads: Created event conversion goal', [
                'name' => $params['name'],
                'ids' => $result['ConversionGoalIds'],
            ]);
            return $result;
        }

        return null;
    }

    /**
     * Get UET tags for the account (required for conversion goals).
     */
    public function getUetTags(): array
    {
        $result = $this->apiCallWithRetry('GetUetTagsByIds', [
            'TagIds' => null,
        ]);

        if ($result && isset($result['UetTags']['UetTag'])) {
            $tags = $result['UetTags']['UetTag'];
            return isset($tags['Id']) ? [$tags] : $tags;
        }

        return [];
    }

    /**
     * Resolve the UET tag ID for this customer — uses existing tag or creates one.
     * Stores the result on the customer record and returns the tag ID.
     */
    public function resolveUetTagId(): ?string
    {
        if ($this->customer->microsoft_uet_tag_id) {
            return $this->customer->microsoft_uet_tag_id;
        }

        $tags = $this->getUetTags();

        if (!empty($tags)) {
            $tagId = (string) $tags[0]['Id'];
            $this->customer->update(['microsoft_uet_tag_id' => $tagId]);

            Log::info('Microsoft Ads: Resolved UET tag ID from account', [
                'customer_id' => $this->customer->id,
                'tag_id'      => $tagId,
            ]);

            return $tagId;
        }

        $result = $this->createUetTag('Spectra — ' . $this->customer->name);
        if (!$result) {
            return null;
        }

        $tag   = $result['UetTags']['UetTag'][0] ?? $result['UetTags']['UetTag'] ?? null;
        $tagId = $tag ? (string) $tag['Id'] : null;

        if ($tagId) {
            $this->customer->update(['microsoft_uet_tag_id' => $tagId]);
        }

        return $tagId;
    }

    /**
     * Create a UET tag for the account.
     */
    public function createUetTag(string $name, string $description = ''): ?array
    {
        $result = $this->apiCallWithRetry('AddUetTags', [
            'UetTags' => ['UetTag' => [[
                'Name' => $name,
                'Description' => $description ?: "UET tag for {$this->customer->name}",
            ]]],
        ]);

        if ($result && isset($result['UetTags'])) {
            Log::info('Microsoft Ads: Created UET tag', [
                'name' => $name,
                'result' => $result['UetTags'],
            ]);
            return $result;
        }

        return null;
    }

    /**
     * Get conversion count for the last 30 days via the reporting API.
     */
    public function getConversionCountLast30Days(): int
    {
        $result = $this->reportingCallWithRetry('SubmitGenerateReport', [
            'ReportRequest' => [
                'Type' => 'ConversionPerformanceReportRequest',
                'Format' => 'Tsv',
                'ReportName' => 'ConversionReport',
                'Aggregation' => 'Summary',
                'Time' => [
                    'PredefinedTime' => 'LastThirtyDays',
                ],
                'Scope' => [
                    'AccountIds' => ['long' => [$this->customer->microsoft_ads_account_id]],
                ],
                'Columns' => ['ConversionPerformanceReportColumn' => [
                    'AccountId',
                    'Conversions',
                    'AllConversions',
                ]],
            ],
        ]);

        if ($result && isset($result['ReportRequestId'])) {
            Log::info('Microsoft Ads: Conversion report submitted', [
                'request_id' => $result['ReportRequestId'],
            ]);
        }

        // Reporting is async — return 0 for now, actual polling handled by PerformanceService
        return 0;
    }
}
