<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\GeminiService;
use App\Services\GoogleAds\GoogleAdRankRepair;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleAdRankCopyPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): array
    {
        $customer = Customer::factory()->create(['website' => 'https://setup.example']);
        $customer->pages()->create(['url' => 'https://setup.example/offer', 'title' => 'Google Ads Setup',
            'content' => 'Google Ads campaign setup is US$999 once. No recurring agency management fees. Ad spend is paid separately to Google.']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'landing_page_url' => 'https://setup.example/offer',
            'product_focus' => 'Google Ads campaign setup', 'keywords' => ['google ads setup']]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);

        return [$campaign->load('customer'), $strategy];
    }

    public function test_rank_generation_uses_the_same_source_fee_and_buyer_benefit_policy(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $proposal = ['headlines' => ['Google Ads Setup', 'No Recurring Agency Fees', 'Explore Our Setup Service'],
            'descriptions' => ['Get a campaign prepared for your business.', 'Ad spend is paid separately to Google.']];
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('generateContent')->willReturnCallback(function ($model, $prompt, $config, $systemInstruction) use ($proposal): array {
            $this->assertSame(config('ai.models.default'), $model);
            $this->assertSame(['responseMimeType' => 'application/json', 'temperature' => 0.2,
                'responseJsonSchema' => \App\Prompts\AdCopyResponseSchema::rankRepair()], $config);
            $this->assertStringContainsString('6–8 strong distinct headlines and 3 short descriptions', $prompt);
            $this->assertStringContainsString('3–15 headlines and 2–4 descriptions', $prompt);
            $this->assertStringContainsString('aim for 25 or fewer', $systemInstruction);
            $this->assertStringContainsString('aim for 75 or fewer', $systemInstruction);
            $this->assertStringContainsString('absolute limit of 30 Unicode characters', $systemInstruction);
            $this->assertStringContainsString('absolute limit of 90 Unicode characters', $systemInstruction);
            $this->assertStringContainsString('Never remove an agency/service fee qualifier', $systemInstruction);
            $this->assertStringContainsString('Prefer a smaller, strong set', $prompt);
            $this->assertStringContainsString('No Recurring Fees', $prompt);
            $this->assertStringContainsString('Another headline or description cannot supply the missing qualifier', $prompt);
            $this->assertStringContainsString('at most ONE headline', $prompt);
            $this->assertStringContainsString('two DISTINCT, source-supported customer benefits', $prompt);
            $this->assertStringContainsString('Ad spend is paid separately to Google.', $prompt);

            return ['text' => json_encode($proposal)];
        });
        $service = new RankCopyPolicyFixture($gemini, new AdvertisingEvidence);

        $result = $service->generateForTest($campaign, $strategy);

        $this->assertSame($proposal['headlines'], $result['headlines']);
        $this->assertSame($proposal['descriptions'], $result['descriptions']);
        $this->assertSame([], $result['existing_campaign_assets']);
    }

    public static function misleadingAssets(): array
    {
        return [
            'callout' => [[['field_type' => 'CALLOUT', 'text' => 'No Recurring Fees']]],
            'sitelink label' => [[['field_type' => 'SITELINK', 'text' => 'No Recurring Fees', 'description1' => 'Agency setup service', 'description2' => 'Ad spend is separate']]],
            'sitelink first description' => [[['field_type' => 'SITELINK', 'text' => 'No Recurring Agency Fees', 'description1' => 'No Recurring Fees', 'description2' => 'Ad spend is separate']]],
            'sitelink second description' => [[['field_type' => 'SITELINK', 'text' => 'No Recurring Agency Fees', 'description1' => 'Ad spend is separate', 'description2' => 'No Recurring Fees']]],
            'snippet value' => [[['field_type' => 'STRUCTURED_SNIPPET', 'header' => 'Services', 'values' => ['Campaign Setup', 'No Recurring Fees', 'Keyword Research']]]],
        ];
    }

    #[DataProvider('misleadingAssets')]
    public function test_each_unqualified_fee_asset_is_blocked_before_model_review(array $plans): void
    {
        [$campaign, $strategy] = $this->campaign();
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateContent');
        $service = new RankCopyPolicyFixture($gemini, new AdvertisingEvidence);

        $this->assertFalse($service->reviewAssetsForTest($campaign, $strategy, $plans));
    }

    public function test_a_qualified_service_fee_claim_still_requires_independent_asset_review(): void
    {
        [$campaign, $strategy] = $this->campaign();
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->exactly(2))->method('generateContent')->willReturnOnConsecutiveCalls(
            ['text' => json_encode(['factual_accuracy' => true, 'intent_relevance' => true, 'blocking_issues' => []])],
            ['text' => json_encode(['factual_accuracy' => false, 'intent_relevance' => true, 'blocking_issues' => ['Unsupported additional claim.']])],
        );
        $service = new RankCopyPolicyFixture($gemini, new AdvertisingEvidence);
        $plans = [['field_type' => 'CALLOUT', 'text' => 'No Recurring Agency Fees']];

        $this->assertTrue($service->reviewAssetsForTest($campaign, $strategy, $plans));
        $this->assertFalse($service->reviewAssetsForTest($campaign, $strategy, $plans));
    }
}

class RankCopyPolicyFixture extends GoogleAdRankRepair
{
    public function generateForTest(Campaign $campaign, Strategy $strategy): array
    {
        return parent::generateCopy($campaign, $strategy, [], ['keywords' => []], false);
    }

    public function reviewAssetsForTest(Campaign $campaign, Strategy $strategy, array $plans): bool
    {
        return parent::reviewAssets($campaign, $strategy, $plans);
    }

    protected function assets(Campaign $campaign): array
    {
        return [];
    }
}
