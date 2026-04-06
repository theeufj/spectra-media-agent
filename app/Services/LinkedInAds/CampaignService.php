<?php

namespace App\Services\LinkedInAds;

use Illuminate\Support\Facades\Log;

/**
 * LinkedIn Ads Campaign Management Service.
 *
 * Handles campaign CRUD, ad account management, and campaign groups.
 * Uses LinkedIn Marketing API REST endpoints.
 *
 * Campaign hierarchy: Ad Account → Campaign Group → Campaign → Creative
 */
class CampaignService extends BaseLinkedInAdsService
{
    /**
     * Get the ad account info.
     */
    public function getAdAccount(string $accountId): ?array
    {
        return $this->apiCall("adAccounts/{$accountId}");
    }

    /**
     * List campaigns for an ad account.
     */
    public function listCampaigns(?string $accountId = null): array
    {
        $accountId = $accountId ?? $this->customer->linkedin_ads_account_id;
        if (!$accountId) return [];

        $result = $this->apiCall('adCampaigns', 'GET', null, [
            'q' => 'search',
            'search' => "(account:(values:List(urn:li:sponsoredAccount:{$accountId})))",
        ]);

        return $result['elements'] ?? [];
    }

    /**
     * Create a Sponsored Content campaign.
     */
    public function createSponsoredContentCampaign(array $params): ?array
    {
        $accountId = $this->customer->linkedin_ads_account_id;
        if (!$accountId) return null;

        $campaign = [
            'account' => "urn:li:sponsoredAccount:{$accountId}",
            'name' => $params['name'],
            'type' => 'SPONSORED_UPDATES',
            'costType' => $params['cost_type'] ?? 'CPM',
            'dailyBudget' => [
                'currencyCode' => $this->config['defaults']['currency'] ?? 'USD',
                'amount' => (string) (($params['daily_budget'] ?? 50) * 100), // LinkedIn uses minor currency
            ],
            'objectiveType' => $params['objective'] ?? 'WEBSITE_VISITS',
            'status' => $params['status'] ?? $this->config['defaults']['status'] ?? 'PAUSED',
            'locale' => [
                'country' => 'US',
                'language' => 'en',
            ],
        ];

        // Audience targeting
        if (!empty($params['targeting'])) {
            $campaign['targetingCriteria'] = $this->buildTargetingCriteria($params['targeting']);
        }

        // Schedule
        if (!empty($params['start_date'])) {
            $campaign['runSchedule'] = [
                'start' => strtotime($params['start_date']) * 1000,
            ];
            if (!empty($params['end_date'])) {
                $campaign['runSchedule']['end'] = strtotime($params['end_date']) * 1000;
            }
        }

        $result = $this->apiCall('adCampaigns', 'POST', $campaign);

        if ($result) {
            Log::info('LinkedIn Ads: Created campaign', [
                'customer_id' => $this->customer->id,
                'name' => $params['name'],
            ]);
        }

        return $result;
    }

    /**
     * Create a Message Ads campaign (InMail).
     */
    public function createMessageAdsCampaign(array $params): ?array
    {
        $accountId = $this->customer->linkedin_ads_account_id;
        if (!$accountId) return null;

        $campaign = [
            'account' => "urn:li:sponsoredAccount:{$accountId}",
            'name' => $params['name'],
            'type' => 'SPONSORED_INMAILS',
            'costType' => 'CPS', // Cost per send
            'dailyBudget' => [
                'currencyCode' => $this->config['defaults']['currency'] ?? 'USD',
                'amount' => (string) (($params['daily_budget'] ?? 50) * 100),
            ],
            'objectiveType' => $params['objective'] ?? 'LEAD_GENERATION',
            'status' => $params['status'] ?? 'PAUSED',
        ];

        if (!empty($params['targeting'])) {
            $campaign['targetingCriteria'] = $this->buildTargetingCriteria($params['targeting']);
        }

        return $this->apiCall('adCampaigns', 'POST', $campaign);
    }

    /**
     * Update campaign status.
     */
    public function updateStatus(string $campaignId, string $status): ?array
    {
        return $this->apiCall("adCampaigns/{$campaignId}", 'PATCH', [
            'patch' => ['$set' => ['status' => $status]],
        ]);
    }

    /**
     * Update campaign budget.
     */
    public function updateBudget(string $campaignId, float $dailyBudget): ?array
    {
        return $this->apiCall("adCampaigns/{$campaignId}", 'PATCH', [
            'patch' => [
                '$set' => [
                    'dailyBudget' => [
                        'currencyCode' => $this->config['defaults']['currency'] ?? 'USD',
                        'amount' => (string) ($dailyBudget * 100),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get campaign by ID.
     */
    public function getCampaign(string $campaignId): ?array
    {
        return $this->apiCall("adCampaigns/{$campaignId}");
    }

    /**
     * Build LinkedIn targeting criteria from a structured array.
     */
    protected function buildTargetingCriteria(array $targeting): array
    {
        $include = ['and' => []];

        // Job titles
        if (!empty($targeting['job_titles'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:titles' => $targeting['job_titles'],
                ],
            ];
        }

        // Job functions
        if (!empty($targeting['job_functions'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:jobFunctions' => $targeting['job_functions'],
                ],
            ];
        }

        // Industries
        if (!empty($targeting['industries'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:industries' => $targeting['industries'],
                ],
            ];
        }

        // Company size
        if (!empty($targeting['company_sizes'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:staffCountRanges' => $targeting['company_sizes'],
                ],
            ];
        }

        // Skills
        if (!empty($targeting['skills'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:skills' => $targeting['skills'],
                ],
            ];
        }

        // Seniority
        if (!empty($targeting['seniorities'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:seniorities' => $targeting['seniorities'],
                ],
            ];
        }

        // Locations
        if (!empty($targeting['locations'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:locations' => $targeting['locations'],
                ],
            ];
        }

        // Specific companies
        if (!empty($targeting['companies'])) {
            $include['and'][] = [
                'or' => [
                    'urn:li:adTargetingFacet:employers' => $targeting['companies'],
                ],
            ];
        }

        return [
            'include' => $include,
            'exclude' => $targeting['exclude'] ?? new \stdClass(),
        ];
    }

    /**
     * Set up LinkedIn Insight Tag (conversion tracking).
     */
    public function getInsightTag(): ?array
    {
        $accountId = $this->customer->linkedin_ads_account_id;
        if (!$accountId) return null;

        return $this->apiCall("adAccounts/{$accountId}/insightTag");
    }

    /**
     * Create a Lead Gen Form for a campaign.
     */
    public function createLeadGenForm(array $params): ?array
    {
        $accountId = $this->customer->linkedin_ads_account_id;
        if (!$accountId) return null;

        $form = [
            'account' => "urn:li:sponsoredAccount:{$accountId}",
            'name' => $params['name'],
            'headline' => $params['headline'],
            'description' => $params['description'],
            'privacyPolicyUrl' => $params['privacy_policy_url'],
            'questions' => $this->buildFormQuestions($params['questions'] ?? []),
            'thankYouMessage' => $params['thank_you_message'] ?? 'Thank you for your interest!',
        ];

        return $this->apiCall('leadGenForms', 'POST', $form);
    }

    protected function buildFormQuestions(array $questions): array
    {
        return collect($questions)->map(fn ($q) => [
            'predefinedField' => $q['field'] ?? null,
            'customQuestionText' => $q['custom_text'] ?? null,
            'required' => $q['required'] ?? true,
        ])->toArray();
    }
}
