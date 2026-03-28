<?php

namespace Tests\Unit\Agents;

use App\Models\AuditSession;
use App\Services\Agents\AccountAuditAgent;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AccountAuditAgentTest extends TestCase
{
    protected AccountAuditAgent $agent;
    protected GeminiService $geminiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiMock = Mockery::mock(GeminiService::class);
        $this->agent = new AccountAuditAgent($this->geminiMock);
    }

    public function test_successful_google_audit(): void
    {
        $session = new AuditSession([
            'token' => 'test-token-google',
            'email' => 'test@example.com',
            'platform' => 'google',
            'access_token_encrypted' => Crypt::encryptString('fake-access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('fake-refresh-token'),
            'google_ads_customer_id' => '1234567890',
            'status' => 'pending',
        ]);
        $session->id = 1;

        // The agent uses inline anonymous BaseGoogleAdsService classes.
        // We mock at the Http level since the underlying Google Ads client uses HTTP.
        // Since the agent creates anonymous classes that extend BaseGoogleAdsService,
        // we need to mock the entire auditGoogleAds flow via a partial mock.
        $agentMock = Mockery::mock(AccountAuditAgent::class, [$this->geminiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $agentMock->shouldReceive('auditGoogleAds')
            ->once()
            ->andReturn([
                'campaigns' => [
                    'total_campaigns' => 3,
                    'active_campaigns' => 2,
                    'total_spend_30d' => 1500.00,
                    'total_conversions_30d' => 25,
                    'wasted_spend_30d' => 0,
                ],
            ]);

        // Mock generateRecommendations since the mocked auditGoogleAds doesn't populate findings
        $agentMock->shouldReceive('generateRecommendations')
            ->once()
            ->andReturn([
                ['title' => 'Increase budget on top campaign', 'description' => 'Your best campaign is underspending.', 'priority' => 1],
            ]);

        Log::spy();

        $results = $agentMock->audit($session);

        $this->assertEquals('google', $results['platform']);
        $this->assertArrayHasKey('score', $results);
        $this->assertArrayHasKey('findings', $results);
        $this->assertArrayHasKey('recommendations', $results);
        $this->assertArrayHasKey('summary', $results);
        $this->assertIsInt($results['score']);
        $this->assertGreaterThanOrEqual(0, $results['score']);
        $this->assertLessThanOrEqual(100, $results['score']);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_successful_facebook_audit(): void
    {
        $session = new AuditSession([
            'token' => 'test-token-facebook',
            'email' => 'test@example.com',
            'platform' => 'facebook',
            'access_token_encrypted' => Crypt::encryptString('fake-fb-access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('fake-fb-refresh-token'),
            'facebook_ad_account_id' => 'act_123456789',
            'status' => 'pending',
        ]);
        $session->id = 2;

        // Mock all Facebook Graph API calls
        Http::fake([
            'https://graph.facebook.com/v22.0/act_123456789/campaigns*' => Http::response([
                'data' => [
                    ['id' => '111', 'name' => 'Campaign A', 'status' => 'ACTIVE', 'objective' => 'CONVERSIONS'],
                ],
            ]),
            'https://graph.facebook.com/v22.0/act_123456789/insights*' => Http::response([
                'data' => [
                    [
                        'campaign_id' => '111',
                        'campaign_name' => 'Campaign A',
                        'impressions' => '50000',
                        'clicks' => '1500',
                        'spend' => '250.50',
                        'frequency' => '1.8',
                        'reach' => '27000',
                        'cpc' => '0.17',
                        'cpm' => '5.01',
                        'actions' => [
                            ['action_type' => 'purchase', 'value' => '12'],
                        ],
                    ],
                ],
            ]),
            'https://graph.facebook.com/v22.0/act_123456789/adsets*' => Http::response([
                'data' => [
                    ['id' => '222', 'name' => 'Ad Set 1', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE'],
                ],
            ]),
            'https://graph.facebook.com/v22.0/act_123456789/ads*' => Http::response([
                'data' => [
                    ['id' => '333', 'name' => 'Ad 1', 'adset_id' => '222', 'effective_status' => 'ACTIVE'],
                ],
            ]),
            'https://graph.facebook.com/v22.0/act_123456789/adspixels*' => Http::response([
                'data' => [
                    ['id' => '555', 'name' => 'My Pixel', 'is_created_by_business' => true],
                ],
            ]),
            'https://graph.facebook.com/*' => Http::response(['data' => []]),
        ]);

        // Mock Gemini for recommendations
        $this->geminiMock->shouldReceive('generateContent')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    ['title' => 'Diversify creatives', 'description' => 'Add more ad variations.', 'priority' => 2],
                ]),
            ]);

        Log::spy();

        $results = $this->agent->audit($session);

        $this->assertEquals('facebook', $results['platform']);
        $this->assertArrayHasKey('score', $results);
        $this->assertArrayHasKey('findings', $results);
        $this->assertArrayHasKey('recommendations', $results);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_handles_api_errors_gracefully(): void
    {
        $session = new AuditSession([
            'token' => 'test-token-error',
            'email' => 'test@example.com',
            'platform' => 'facebook',
            'access_token_encrypted' => Crypt::encryptString('bad-token'),
            'refresh_token_encrypted' => Crypt::encryptString('bad-refresh'),
            'facebook_ad_account_id' => 'act_bad',
            'status' => 'pending',
        ]);
        $session->id = 3;

        // Simulate Facebook API throwing an exception
        Http::fake(function () {
            throw new \Exception('Invalid OAuth 2.0 Access Token');
        });

        // Gemini generates fallback recommendations from findings
        $this->geminiMock->shouldReceive('generateContent')
            ->andReturn(null);

        Log::spy();

        $results = $this->agent->audit($session);

        // Should still return a structured result, not throw
        $this->assertEquals('facebook', $results['platform']);
        $this->assertArrayHasKey('score', $results);
        $this->assertArrayHasKey('findings', $results);
        // Should have logged the error
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_unsupported_platform_returns_error(): void
    {
        $session = new AuditSession([
            'token' => 'test-token-unknown',
            'email' => 'test@example.com',
            'platform' => 'tiktok',
            'access_token_encrypted' => Crypt::encryptString('token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh'),
            'status' => 'pending',
        ]);
        $session->id = 4;

        // Gemini mock for recommendations on empty findings
        $this->geminiMock->shouldReceive('generateContent')->andReturn(null);

        $results = $this->agent->audit($session);

        $this->assertEquals('tiktok', $results['platform']);
        $this->assertArrayHasKey('score', $results);
    }
}
