<?php

namespace Tests\Unit\Agents;

use App\Models\Customer;
use App\Services\Agents\AudienceIntelligenceAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AudienceIntelligenceAgentTest extends TestCase
{
    protected AudienceIntelligenceAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->agent = new AudienceIntelligenceAgent($this->geminiMock);
    }

    public function test_generate_segmentation_recommendations_success(): void
    {
        $customer = new Customer([
            'name' => 'Acme Corp',
            'business_type' => 'B2B',
            'website' => 'https://acme.com',
        ]);
        $customer->id = 1;

        $expectedSegments = [
            'segments' => [
                [
                    'name' => 'Decision Makers',
                    'description' => 'C-level executives and VPs',
                    'targeting' => [
                        'interests' => ['business software', 'enterprise solutions'],
                        'demographics' => ['age_range' => '35-54', 'gender' => 'all', 'income' => 'top 30%'],
                        'behaviors' => ['business page admins'],
                    ],
                    'expected_size' => 'medium',
                    'platforms' => ['google', 'facebook'],
                    'bid_adjustment' => '+20%',
                    'priority' => 'high',
                ],
                [
                    'name' => 'IT Professionals',
                    'description' => 'Software engineers and IT managers',
                    'targeting' => [
                        'interests' => ['technology', 'SaaS'],
                        'demographics' => ['age_range' => '25-44', 'gender' => 'all', 'income' => 'top 50%'],
                        'behaviors' => ['tech early adopters'],
                    ],
                    'expected_size' => 'large',
                    'platforms' => ['google'],
                    'bid_adjustment' => '+10%',
                    'priority' => 'medium',
                ],
            ],
            'lookalike_recommendations' => [
                [
                    'source' => 'Existing customers list',
                    'similarity' => '1-2%',
                    'platform' => 'facebook',
                    'rationale' => 'High-value customer base for expansion',
                ],
            ],
        ];

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->withArgs(function ($model, $prompt, $config, $systemPrompt, $thinking) {
                return $model === 'gemini-3-flash-preview' && str_contains($prompt, 'Acme Corp');
            })
            ->andReturn(['text' => json_encode($expectedSegments)]);

        Log::spy();

        $result = $this->agent->generateSegmentationRecommendations($customer);

        $this->assertArrayHasKey('segments', $result);
        $this->assertCount(2, $result['segments']);
        $this->assertEquals('Decision Makers', $result['segments'][0]['name']);
        $this->assertArrayHasKey('lookalike_recommendations', $result);

        Log::shouldHaveReceived('info')->once();
    }

    public function test_generate_segmentation_handles_empty_customer_data(): void
    {
        $customer = new Customer([
            'name' => 'Bare Minimum LLC',
            'business_type' => null,
            'website' => null,
            'description' => null,
        ]);
        $customer->id = 2;

        // Gemini still gets called, but returns minimal data
        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn(['text' => json_encode(['segments' => [], 'lookalike_recommendations' => []])]);

        $result = $this->agent->generateSegmentationRecommendations($customer);

        $this->assertIsArray($result);
        $this->assertEmpty($result['segments']);
    }

    public function test_generate_segmentation_handles_gemini_failure(): void
    {
        $customer = new Customer(['name' => 'Test Company']);
        $customer->id = 3;

        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andThrow(new \Exception('API rate limit exceeded'));

        Log::spy();

        $result = $this->agent->generateSegmentationRecommendations($customer);

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_analyze_audience_performance_returns_recommendations(): void
    {
        $customer = new Customer([
            'name' => 'Test Company',
            'google_ads_customer_id' => '1234567890',
        ]);
        $customer->id = 4;

        // Mock the agent to stub out the Google Ads call inside getCustomerMatchAudiences
        $agentMock = Mockery::mock(AudienceIntelligenceAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('getCustomerMatchAudiences')
            ->once()
            ->andReturn([
                [
                    'name' => 'High Value Customers',
                    'size_display' => 5000,
                    'size_search' => 4000,
                ],
                [
                    'name' => 'Tiny Test List',
                    'size_display' => 200,
                    'size_search' => 100,
                ],
            ]);

        $result = $agentMock->analyzeAudiencePerformance($customer);

        $this->assertArrayHasKey('audiences', $result);
        $this->assertCount(2, $result['audiences']);
        $this->assertArrayHasKey('recommendations', $result);

        // Should flag the tiny list
        $tinyListRec = collect($result['recommendations'])->firstWhere('audience', 'Tiny Test List');
        $this->assertNotNull($tinyListRec);
        $this->assertStringContainsString('too small', $tinyListRec['issue']);
    }
}
