<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use App\Services\GoogleAds\DSAServices\AddDSATarget;
use App\Services\GoogleAds\DSAServices\CreateDSAAdGroup;
use App\Services\GoogleAds\DSAServices\CreateDSACampaign;
use App\Services\GoogleAds\DSAServices\CreateExpandedDynamicSearchAd;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use Illuminate\Support\Facades\Log;

/**
 * Creates and manages Dynamic Search Ads (DSA) campaigns for customers
 * using their crawled website content as the target source.
 *
 * Responsibilities:
 *   Setup:    Create DSA campaign + ad groups per site section + ads with AI descriptions
 *   Monitor:  Surface high-volume DSA search terms not in regular campaigns (weekly)
 *   Expand:   Add new URL targets as KnowledgeBase records are added
 */
class DSAManagementAgent
{
    public function __construct(private GeminiService $gemini) {}

    public function manage(Customer $customer): array
    {
        if (!$customer->google_ads_customer_id || !$customer->website) {
            return ['skipped' => true, 'reason' => 'No Google Ads account or website'];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $domain     = parse_url($customer->website, PHP_URL_HOST) ?? $customer->website;
        $language   = 'en';

        $pages     = KnowledgeBase::where('user_id', $customer->users()->value('id'))
            ->whereNotNull('url')
            ->get();

        if ($pages->isEmpty()) {
            return ['skipped' => true, 'reason' => 'No crawled pages in knowledge base'];
        }

        // Check if DSA campaign already exists
        $dsaCampaignName = $customer->name . ' — DSA';
        $createCampaign  = new CreateDSACampaign($customer);

        // Budget: 20% of primary campaign average daily budget or $20 floor
        $primaryBudget = $customer->campaigns()
            ->where('status', 'active')
            ->avg('daily_budget') ?? 100;
        $dsaBudget = max(20, round($primaryBudget * 0.20, 2));

        $campaignResource = ($createCampaign)(
            $customerId,
            $dsaCampaignName,
            $domain,
            $language,
            $dsaBudget
        );

        if (!$campaignResource) {
            return ['success' => false, 'error' => 'Failed to create DSA campaign'];
        }

        // Group pages by inferred section
        $sections = $this->groupPagesBySection($pages);

        $created = [];
        $errors  = [];

        foreach ($sections as $sectionName => $sectionPages) {
            $adGroupName    = $dsaCampaignName . ' — ' . ucfirst($sectionName);
            $createAdGroup  = new CreateDSAAdGroup($customer);
            $adGroupResource = ($createAdGroup)($customerId, $campaignResource, $adGroupName);

            if (!$adGroupResource) {
                $errors[] = "Failed to create ad group: {$adGroupName}";
                continue;
            }

            // Add URL targets for each page in this section
            $addTarget = new AddDSATarget($customer);
            foreach ($sectionPages as $page) {
                if (!$page->url) continue;
                ($addTarget)(
                    $customerId,
                    $adGroupResource,
                    basename(parse_url($page->url, PHP_URL_PATH) ?? $page->url),
                    'url',
                    $page->url
                );
            }

            // Generate AI descriptions for this section
            $descriptions = $this->generateDescriptions($customer, $sectionName, $sectionPages->pluck('content')->filter()->implode("\n\n"));

            if (!empty($descriptions)) {
                $createAd = new CreateExpandedDynamicSearchAd($customer);
                ($createAd)($customerId, $adGroupResource, $descriptions);
            }

            $created[] = [
                'ad_group'  => $adGroupName,
                'pages'     => $sectionPages->count(),
            ];
        }

        AgentActivity::record(
            'dsa',
            'dsa_campaign_setup',
            "Created DSA campaign with " . count($created) . " ad group(s) for \"{$customer->name}\"",
            $customer->id,
            null,
            ['campaign' => $dsaCampaignName, 'ad_groups' => $created, 'errors' => $errors]
        );

        Log::info('DSAManagementAgent: Setup complete', [
            'customer_id'      => $customer->id,
            'campaign'         => $dsaCampaignName,
            'ad_groups_created' => count($created),
        ]);

        return [
            'success'   => true,
            'campaign'  => $dsaCampaignName,
            'created'   => $created,
            'errors'    => $errors,
        ];
    }

    /**
     * Group KnowledgeBase pages into site sections based on URL path patterns.
     */
    private function groupPagesBySection($pages): array
    {
        $sections = [];

        foreach ($pages as $page) {
            $path = strtolower(parse_url($page->url ?? '', PHP_URL_PATH) ?? '');

            $section = 'general';

            foreach ([
                'service' => ['service', 'offering', 'solution', 'product'],
                'blog'    => ['blog', 'article', 'news', 'post', 'insight'],
                'about'   => ['about', 'team', 'story', 'mission'],
                'contact' => ['contact', 'location', 'address'],
                'pricing' => ['pricing', 'price', 'plan', 'cost'],
            ] as $name => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($path, $kw)) {
                        $section = $name;
                        break 2;
                    }
                }
            }

            $sections[$section][] = $page;
        }

        return array_map(fn($items) => collect($items), $sections);
    }

    private function generateDescriptions(Customer $customer, string $section, string $content): array
    {
        $truncated = mb_substr($content, 0, 2000);

        $prompt = <<<PROMPT
You are an expert Google Ads copywriter. Write 2 ad descriptions for a Dynamic Search Ad targeting the "{$section}" section of {$customer->name}'s website.

Website content for this section:
{$truncated}

Requirements:
- Each description max 90 characters
- Highlight the value proposition and include a call to action
- Do NOT include the headline (Google generates it automatically from the page)

Return ONLY valid JSON array: ["description 1", "description 2"]
PROMPT;

        try {
            $response = $this->gemini->generateContent('gemini-2.0-flash', $prompt);
            $text = $response['text'] ?? '';
            $text = preg_replace('/```json\s*|\s*```/', '', $text);
            $data = json_decode(trim($text), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return array_slice($data, 0, 2);
            }
        } catch (\Exception $e) {
            Log::error('DSAManagementAgent: Description generation failed: ' . $e->getMessage());
        }

        return [
            'Discover our ' . $section . ' — Get in touch today.',
            'Quality ' . $section . ' solutions. Contact us now.',
        ];
    }
}
