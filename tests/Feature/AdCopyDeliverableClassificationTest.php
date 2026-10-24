<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Prompts\AdCopyGenerationPolicy;
use App\Prompts\AdCopyPrompt;
use App\Prompts\AdCopyReviewPrompt;
use App\Services\AdminMonitorService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdCopyDeliverableClassificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_generation_and_review_share_the_boundary_between_purchased_work_and_administration(): void
    {
        $evidence = ['offer_brief' => 'Campaign setup service', 'sources' => [[
            'content' => 'We research keywords, write your search ads and configure conversion tracking.',
        ]]];
        $prompts = [
            (new AdCopyPrompt('Promote campaign setup.', 'Google Ads', evidence: $evidence))->getPrompt(),
            (new AdCopyReviewPrompt('Google Ads', 'Campaign Setup', 'Keywords researched for you.', $evidence))->getPrompt(),
        ];

        foreach ($prompts as $prompt) {
            $this->assertStringContainsString(AdCopyGenerationPolicy::deliverableClassificationInstructions(), $prompt);
            $this->assertStringContainsString('not administrative workflow merely because it is a setup task', $prompt);
            $this->assertStringContainsString("'Keywords Researched for You', 'Ad Copy Written for Search' and 'Conversion Tracking Set Up'", $prompt);
            $this->assertStringContainsString('Distinct sourced work done for the buyer can count as concrete reasons', $prompt);
            $this->assertStringContainsString('Do not require an unproven sales, traffic, savings or performance promise', $prompt);
            $this->assertStringContainsString('Account invitations, paused status, approval queues and handover mechanics remain administrative workflow', $prompt);
            $this->assertStringContainsString('This is not a blanket exemption for setup offers', $prompt);
            $this->assertStringContainsString('apply all fee-scope and source-proof checks', $prompt);
            $this->assertStringContainsString('Examples are classification guidance, never evidence', $prompt);
            $this->assertStringContainsString('at least two DISTINCT, source-supported customer benefits', $prompt);
        }
    }

    public static function feeClaims(): array
    {
        return [
            'supported qualified fee advantage' => ['No Recurring Agency Fees', true],
            'unqualified waiver remains misleading' => ['No Recurring Fees', false],
        ];
    }

    #[DataProvider('feeClaims')]
    public function test_sourced_deliverables_reach_the_reviewer_without_bypassing_fee_proof(string $feeHeadline, bool $expectedApproval): void
    {
        $customer = Customer::factory()->create(['website' => 'https://campaign-setup.example']);
        $source = 'Our campaign setup service researches keywords, writes search ads and configures conversion tracking. '
            .'The service costs EUR120 once with no recurring agency fees. Advertising spend is paid separately to Google.';
        $customer->pages()->create(['url' => $customer->website, 'title' => 'Campaign setup', 'content' => $source]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'product_focus' => 'Campaign setup service']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads']);
        $copy = ['headlines' => ['Campaign Setup Service', 'Keywords Researched for You', 'Ad Copy Written for Search',
            'Conversion Tracking Set Up', $feeHeadline],
            'descriptions' => ['Get keywords researched, search ads written and conversion tracking set up.',
                'EUR120 is the one-time service fee. Advertising spend is paid separately to Google.',
                'Explore the campaign setup service and the work we do for your business.']];
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturnCallback(function ($model, $prompt) use ($source, $feeHeadline, $expectedApproval) {
            $this->assertStringContainsString(AdCopyGenerationPolicy::deliverableClassificationInstructions(), $prompt);
            $this->assertStringContainsString($source, $prompt);
            $this->assertStringContainsString($feeHeadline, $prompt);
            if (! $expectedApproval) {
                $this->assertStringContainsString('fee_scope_misleading_recurring_claim', $prompt);
            }

            return ['text' => json_encode(['overall_score' => 88, 'factual_accuracy' => true,
                'intent_relevance' => true, 'blocking_issues' => [], 'feedback' => [
                    'headlines' => ['1. offer — campaign setup', '2. customer benefit — keyword research done for the buyer',
                        '3. customer benefit — search ad copy written for the buyer', '4. offer — configured tracking deliverable',
                        '5. customer benefit — commercial fee advantage'], 'descriptions' => [],
                ]], JSON_THROW_ON_ERROR)];
        });
        $review = (new AdminMonitorService($model))->reviewAdCopy(new AdCopy([
            'strategy_id' => $strategy->id, 'platform' => 'Google Ads', ...$copy,
        ]));

        $this->assertSame($expectedApproval, $review['programmatic_validation']['fee_scope']['is_valid']);
        $this->assertSame($expectedApproval ? 'approved' : 'needs_revision', $review['overall_status']);
        $this->assertTrue($review['gemini_feedback']['intent_relevance']);
    }
}
