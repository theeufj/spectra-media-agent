<?php

namespace Tests\Unit;

use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\Campaigns\SearchOfferGroups;
use App\Services\Deployment\GoogleSearchConfigurationCheck;
use App\Services\GeminiService;
use Tests\TestCase;

class SearchOfferGroupsTest extends TestCase
{
    private const HEADLINES = ['Search Ads Setup', 'Get Keywords Researched', 'Get Tracking Configured'];

    private const DESCRIPTIONS = ['Get your Search campaign built.', 'Prepare conversion tracking for your campaign.'];

    public function test_it_only_uses_reviewed_pages_and_researched_keywords(): void
    {
        $groups = $this->generate();

        $this->assertSame(['https://example.com/setup', 'https://example.com/management'], array_column($groups, 'url'));
        $this->assertSame(['google ads setup', 'google ads management'], array_column(SearchOfferGroups::keywords($groups), 'text'));
        $this->assertNotSame($groups[0]['keywords'][0]['ad_group_resource'], $groups[1]['keywords'][0]['ad_group_resource']);
    }

    public function test_a_keyword_cannot_be_assigned_to_two_offers(): void
    {
        $this->expectExceptionMessage('have one destination');

        $this->generate(duplicate: true);
    }

    public function test_an_offer_that_fails_independent_review_is_held(): void
    {
        $this->expectExceptionMessage('Check that their keywords, ads and landing pages match');

        $this->generate(approved: false);
    }

    public function test_google_length_limits_are_enforced_without_rewriting_valid_copy(): void
    {
        $groups = $this->generate(extraHeadline: str_repeat('x', 31));

        $this->assertSame(self::HEADLINES, $groups[0]['headlines']);
    }

    public function test_it_prompts_for_a_correction_when_too_few_assets_fit(): void
    {
        $this->assertSame(self::HEADLINES, $this->generate(retry: true)[0]['headlines']);
    }

    public function test_readback_detects_keywords_and_destinations_crossed_between_groups(): void
    {
        $expected = ['offer_ad_groups' => [], 'keywords' => [], 'ads' => []];
        $actual = ['keywords' => [], 'ads' => []];
        foreach ($this->generate() as $index => $group) {
            $resource = 'customers/1234567890/adGroups/'.(100 + $index);
            $expected['offer_ad_groups'][] = $resource;
            $expected['keywords'][] = [...$group['keywords'][0], 'ad_group_resource' => $resource];
            $expected['ads'][] = ['ad_group_resource' => $resource, 'headlines' => $group['headlines'],
                'descriptions' => $group['descriptions'], 'final_urls' => [$group['url']]];
            $actual['keywords'][] = ['adGroupCriterion' => ['adGroup' => $resource,
                'keyword' => ['text' => $group['keywords'][0]['text'], 'matchType' => 'PHRASE']]];
            $actual['ads'][] = ['adGroup' => ['resourceName' => $resource, 'status' => 'ENABLED'],
                'adGroupAd' => ['status' => 'ENABLED', 'ad' => ['finalUrls' => [$group['url']],
                    'responsiveSearchAd' => [
                        'headlines' => array_map(fn ($text) => ['text' => $text], $group['headlines']),
                        'descriptions' => array_map(fn ($text) => ['text' => $text], $group['descriptions']),
                    ]]]];
        }
        $check = new GoogleSearchConfigurationCheck;
        $this->assertSame([], $check->compareOfferGroups($expected, $actual));

        $actual['keywords'][0]['adGroupCriterion']['adGroup'] = $expected['offer_ad_groups'][1];
        $actual['ads'][0]['adGroupAd']['ad']['finalUrls'] = $expected['ads'][1]['final_urls'];

        $this->assertCount(2, $check->compareOfferGroups($expected, $actual));
    }

    private function generate(bool $duplicate = false, bool $approved = true, ?string $extraHeadline = null, bool $retry = false): array
    {
        $pages = [
            ['url' => 'https://example.com/setup', 'title' => 'Google Ads Setup', 'content' => 'One-time Search campaign build and conversion tracking.'],
            ['url' => 'https://example.com/management', 'title' => 'Google Ads Management', 'content' => 'Ongoing management, optimisation and reporting.'],
        ];
        $keywords = array_map(fn ($text) => ['text' => $text, 'match_type' => 'PHRASE'],
            ['google ads setup', 'google ads management', 'google ads jobs']);
        $copy = ['headlines' => self::HEADLINES, 'descriptions' => self::DESCRIPTIONS];
        if ($extraHeadline !== null) {
            $copy['headlines'][] = $extraHeadline;
        }
        $response = ['groups' => [
            ['page_id' => 0, 'keyword_ids' => [0], ...$copy],
            ['page_id' => 1, 'keyword_ids' => [$duplicate ? 0 : 1],
                'headlines' => ['Search Ads Management', 'Ongoing Campaign Monitoring', 'Review Campaign Performance'],
                'descriptions' => ['Get ongoing help with Search Ads.', 'Review delivery and keep track of your ad performance.']],
        ]];
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->exactly($retry ? 3 : ($duplicate ? 1 : 2)))->method('generateContent')
            ->willReturnCallback(function (string $model, string $prompt) use ($response, $approved, $retry): array {
                if (str_contains($prompt, 'Independently review')) {
                    $this->assertStringContainsString('landing_page_evidence', $prompt);

                    return ['text' => json_encode(['approved' => $approved], JSON_THROW_ON_ERROR)];
                }
                if ($retry && ! str_contains($prompt, 'at least 3 usable, distinct assets of at most 30 characters')) {
                    $response['groups'][0]['headlines'] = [str_repeat('x', 31)];
                }

                return ['text' => json_encode($response, JSON_THROW_ON_ERROR)];
            });

        return (new SearchOfferGroups(new AdvertisingEvidence, $gemini))->generate($pages, $keywords, 'Google Ads services');
    }
}
