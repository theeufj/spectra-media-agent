<?php

namespace Tests\Feature;

use App\Jobs\ProvisionGoogleAdsAccount;
use App\Jobs\ResearchCampaignMarket;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Campaigns\CampaignMarketResearch;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignMarketResearchWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private Campaign $campaign;

    private Strategy $strategy;

    private Customer $customer;

    private CampaignMarketResearchWorkflow $workflow;

    private array $proposal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['google_ads_customer_id' => '123', 'service_type' => 'managed']);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $this->customer->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->withSession(['active_customer_id' => $this->customer->id]);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'daily_budget' => '5.00',
            'approved_daily_budget' => null, 'budget_confirmed_at' => null, 'auto_generated_at' => null,
            'keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT']], 'search_delivery_state' => ['diagnosis' => 'preserve']]);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'search',
            'daily_budget' => '5.00', 'bidding_strategy' => ['keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT']],
                'parameters' => ['cpcBidCeilingMicros' => 3_000_000]]]);
        $this->proposal = ['status' => 'ready', 'proposed_daily_budget_micros' => 10_000_000, 'strategies' => [[
            'strategy_id' => $this->strategy->id, 'platform' => 'Google Ads', 'channel' => 'search', 'status' => 'ready',
            'budget_micros' => 10_000_000, 'cpc_bid_micros' => 2_000_000, 'bidding_strategy' => 'MANUAL_CPC',
            'proposed_keywords' => [['text' => 'relevant researched keyword', 'match_type' => 'PHRASE']],
        ]]];
        $provider = $this->createMock(CampaignMarketResearch::class);
        $provider->method('fingerprint')->willReturnCallback(fn (Campaign $campaign, ?int $budget = null) => $this->fingerprint($campaign, $budget));
        $provider->method('approvalFingerprint')->willReturnCallback(fn (Campaign $campaign) => $this->fingerprint($campaign));
        $provider->method('research')->willReturnCallback(fn (Campaign $campaign, ?int $budget = null) => [...$this->proposal,
            'fingerprint' => $this->fingerprint($campaign, $budget), 'checked_at' => now()->toIso8601String()]);
        $this->workflow = new CampaignMarketResearchWorkflow($provider);
        $this->app->instance(CampaignMarketResearchWorkflow::class, $this->workflow);
    }

    private function fingerprint(Campaign $campaign, ?int $requestedBudget = null): string
    {
        $current = $campaign->fresh()->load('customer', 'strategies.targetingConfig');

        return hash('sha256', json_encode([$requestedBudget, $current->daily_budget, $current->approved_daily_budget, $current->keywords,
            $current->customer->google_ads_customer_id, $current->customer->website,
            $current->strategies->map(fn ($strategy) => [$strategy->id, $strategy->daily_budget, $strategy->bidding_strategy,
                $strategy->targetingConfig?->google_options])->all()], JSON_THROW_ON_ERROR));
    }

    private function researched(): array
    {
        $queued = $this->workflow->request($this->campaign);
        $this->workflow->run($this->campaign, $queued['request_id']);

        return $this->workflow->status($this->campaign);
    }

    public function test_automatic_research_is_queued_once_and_never_changes_an_owner_budget_or_keywords(): void
    {
        $first = $this->workflow->request($this->campaign);
        $second = $this->workflow->request($this->campaign);
        $this->assertSame($first['request_id'], $second['request_id']);
        Queue::assertPushed(ResearchCampaignMarket::class, 1);
        $this->workflow->run($this->campaign, $first['request_id']);
        $this->assertTrue($this->workflow->status($this->campaign)['approvable']);
        $this->assertEquals('5.00', $this->campaign->fresh()->daily_budget);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertSame('old keyword', $this->campaign->fresh()->keywords[0]['text']);
        $this->assertSame('preserve', $this->campaign->fresh()->search_delivery_state['diagnosis']);
    }

    public function test_manual_campaign_accepts_only_its_exact_researched_plan_and_budget(): void
    {
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertEquals('10.00', $this->campaign->fresh()->approved_daily_budget);
        $this->assertEquals('10.00', $this->strategy->fresh()->daily_budget);
        $this->assertSame('relevant researched keyword', $this->campaign->fresh()->keywords[0]['text']);
        $this->assertSame(3_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        $this->assertSame(2_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['initialCpcBidMicros']);
        $this->assertSame('ManualCpc', $this->strategy->fresh()->bidding_strategy['name']);
        $this->workflow->assertApproved($this->campaign);
        $this->assertTrue($this->workflow->status($this->campaign)['approved']);
    }

    public static function refusedApproval(): array
    {
        return [['missing_research'], ['wrong_request'], ['wrong_fingerprint'], ['wrong_amount'], ['not_accepted'], ['expired'], ['changed_keywords'], ['provider_unknown']];
    }

    #[DataProvider('refusedApproval')]
    public function test_stale_missing_unknown_or_unaccepted_research_cannot_authorize_spending(string $reason): void
    {
        $status = $this->researched();
        $body = ['daily_budget' => '10.00', 'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true];
        if ($reason === 'missing_research') {
            $this->campaign->update(['search_delivery_state' => null]);
        } elseif ($reason === 'wrong_request') {
            $body['research_id'] = (string) \Illuminate\Support\Str::uuid();
        } elseif ($reason === 'wrong_fingerprint') {
            $body['research_fingerprint'] = str_repeat('a', 64);
        } elseif ($reason === 'wrong_amount') {
            $body['daily_budget'] = '11.00';
        } elseif ($reason === 'not_accepted') {
            $body['accept_proposal'] = false;
        } elseif ($reason === 'expired') {
            $this->travel(16)->minutes();
        } elseif ($reason === 'changed_keywords') {
            $this->campaign->update(['keywords' => [['text' => 'unresearched', 'match_type' => 'EXACT']]]);
        } else {
            $stored = $this->campaign->fresh()->search_delivery_state;
            $stored['market_research']['status'] = 'unknown';
            $stored['market_research']['result'] = null;
            $this->campaign->update(['search_delivery_state' => $stored]);
        }
        $this->post(route('campaigns.confirm-budget', $this->campaign), $body)->assertSessionHasErrors('daily_budget');
        $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertEquals('5.00', $this->strategy->fresh()->daily_budget);
    }

    public function test_new_request_or_input_change_during_provider_research_cannot_be_overwritten(): void
    {
        foreach (['new_request', 'changed_input'] as $change) {
            $queued = $this->workflow->request($this->campaign, force: true);
            $provider = $this->createMock(CampaignMarketResearch::class);
            $provider->method('fingerprint')->willReturnCallback(fn (Campaign $campaign) => $this->fingerprint($campaign));
            $provider->method('research')->willReturnCallback(function (Campaign $campaign) use ($change): array {
                $fingerprint = $this->fingerprint($campaign);
                if ($change === 'new_request') {
                    $this->workflow->request($campaign, force: true);
                } else {
                    $campaign->update(['keywords' => [['text' => 'new chosen keyword', 'match_type' => 'EXACT']]]);
                }

                return [...$this->proposal, 'fingerprint' => $fingerprint];
            });
            (new CampaignMarketResearchWorkflow($provider))->run($this->campaign, $queued['request_id']);
            $status = $this->workflow->status($this->campaign);
            $this->assertFalse($status['approvable']);
            if ($change === 'new_request') {
                $this->assertNotSame($queued['request_id'], $status['request_id']);
                $this->assertSame('queued', $status['status']);
            } else {
                $this->assertSame('stale', $status['status']);
            }
        }
    }

    public function test_expired_research_and_abandoned_pending_jobs_are_automatically_replaceable(): void
    {
        $ready = $this->researched();
        $this->travel(16)->minutes();
        $queued = $this->workflow->request($this->campaign);
        $this->assertNotSame($ready['request_id'], $queued['request_id']);
        $this->travel(12)->minutes();
        $this->assertSame('stale', $this->workflow->status($this->campaign)['status']);
        $replacement = $this->workflow->request($this->campaign);
        $this->assertNotSame($queued['request_id'], $replacement['request_id']);
    }

    public function test_only_current_failed_job_marks_research_unknown(): void
    {
        $first = $this->workflow->request($this->campaign);
        $second = $this->workflow->request($this->campaign, force: true);
        (new ResearchCampaignMarket($this->campaign, $first['request_id']))->failed(new \RuntimeException('Old failure'));
        $this->assertSame('queued', $this->workflow->status($this->campaign)['status']);
        (new ResearchCampaignMarket($this->campaign, $second['request_id']))->failed(new \RuntimeException('Current failure'));
        $this->assertSame('unknown', $this->workflow->status($this->campaign)['status']);
        $retry = $this->workflow->request($this->campaign, force: true);
        $this->workflow->run($this->campaign, $retry['request_id']);
        $this->assertSame('ready', $this->workflow->status($this->campaign)['status']);
        $this->assertNull($this->workflow->status($this->campaign)['message']);
    }

    public function test_free_automatic_research_never_provisions_an_account_but_explicit_intent_does(): void
    {
        Bus::fake();
        $this->customer->update(['google_ads_customer_id' => null]);
        $this->workflow->request($this->campaign);
        Bus::assertDispatched(ResearchCampaignMarket::class);
        Bus::assertNotDispatched(ProvisionGoogleAdsAccount::class);
        $this->workflow->request($this->campaign, force: true, prepareAccount: true);
        Bus::assertChained([ProvisionGoogleAdsAccount::class, ResearchCampaignMarket::class]);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
    }

    public function test_paid_teammate_access_prepares_account_before_research_without_prior_budget_approval(): void
    {
        Bus::fake();
        $this->customer->update(['google_ads_customer_id' => null]);
        $teammate = User::factory()->create(['subscription_status' => 'active']);
        $this->customer->users()->attach($teammate, ['role' => 'member']);
        $this->workflow->request($this->campaign);
        Bus::assertChained([ProvisionGoogleAdsAccount::class, ResearchCampaignMarket::class]);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
    }

    public function test_a_custom_budget_is_hypothetical_and_fingerprinted_without_saving_or_approving_spend(): void
    {
        $default = $this->workflow->request($this->campaign);
        $this->post(route('campaigns.market-research.refresh', $this->campaign), ['daily_budget' => '10.25'])->assertSessionHasNoErrors();
        $custom = $this->workflow->status($this->campaign);
        $this->assertSame(10_250_000, $custom['requested_daily_budget_micros']);
        $this->assertNotSame($default['input_fingerprint'], $custom['input_fingerprint']);
        $this->assertEquals('5.00', $this->campaign->fresh()->daily_budget);
        $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
    }

    public function test_an_already_approved_plan_can_research_and_explicitly_accept_a_new_custom_proposal(): void
    {
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->proposal['proposed_daily_budget_micros'] = $this->proposal['strategies'][0]['budget_micros'] = 12_500_000;
        $queued = $this->workflow->request($this->campaign, force: true, dailyCents: 1250);
        $this->assertFalse($queued['approved']);
        $this->assertEquals('10.00', $this->campaign->fresh()->approved_daily_budget);
        $this->workflow->run($this->campaign, $queued['request_id']);
        $status = $this->workflow->status($this->campaign);
        $this->assertTrue($status['approvable']);
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '12.50',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertEquals('12.50', $this->campaign->fresh()->approved_daily_budget);
    }

    public function test_acceptance_migrates_shared_draft_keywords_to_the_visible_per_strategy_selections(): void
    {
        $second = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'daily_budget' => '5.00', 'bidding_strategy' => ['name' => 'MaximizeConversions']]);
        $this->strategy->targetingConfig()->create(['platform' => 'Google Ads', 'google_options' => ['keywords' => [['text' => 'stale targeting override', 'match_type' => 'EXACT']]]]);
        $this->proposal['strategies'][0]['budget_micros'] = 5_000_000;
        $this->proposal['strategies'][] = [...$this->proposal['strategies'][0], 'strategy_id' => $second->id,
            'proposed_keywords' => [['text' => 'second researched theme', 'match_type' => 'EXACT']]];
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertNull($this->campaign->fresh()->keywords);
        $builder = new SearchKeywordBuilder($this->customer);
        foreach ([$this->strategy, $second] as $index => $strategy) {
            $keywords = $builder->getKeywords($this->campaign->fresh(), $strategy->fresh(), new ExecutionPlan([]));
            $this->assertSame($this->proposal['strategies'][$index]['proposed_keywords'][0]['text'], $keywords[0]['text']);
            $this->assertSame('ManualCpc', $strategy->fresh()->bidding_strategy['name']);
        }
        $this->workflow->assertApproved($this->campaign);
    }

    public function test_existing_platform_allocation_and_owner_bid_cap_cannot_be_changed_by_accepting_a_proposal(): void
    {
        $this->strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasErrors('daily_budget');
        $this->assertEquals('5.00', $this->strategy->fresh()->daily_budget);
        $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        $this->strategy->update(['google_ads_campaign_id' => null]);
        $this->campaign->update(['google_ads_campaign_id' => null]);
        $this->proposal['strategies'][0]['cpc_bid_micros'] = 4_000_000;
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasErrors('daily_budget');
        $this->assertSame(3_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        $this->assertEquals('5.00', $this->strategy->fresh()->daily_budget);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
    }

    public function test_signoff_routes_cannot_bypass_current_researched_proposal_approval(): void
    {
        $this->post(route('campaigns.strategies.sign-off', [$this->campaign, $this->strategy]))->assertSessionHasErrors('daily_budget');
        $this->post(route('campaigns.sign-off-all', $this->campaign))->assertSessionHasErrors('daily_budget');
        $this->assertNull($this->strategy->fresh()->signed_off_at);
    }

    public function test_customer_changes_after_research_cannot_be_blessed_by_approval(): void
    {
        $status = $this->researched();
        $this->customer->update(['website' => 'https://different.example/']);
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasErrors('daily_budget');
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
    }

    public function test_account_provisioning_overlap_is_serialized_by_customer_not_campaign(): void
    {
        $first = new ProvisionGoogleAdsAccount($this->customer);
        $second = new ProvisionGoogleAdsAccount($this->customer->fresh());
        $this->assertSame($first->middleware()[0]->getLockKey($first), $second->middleware()[0]->getLockKey($second));
        $this->assertSame(3900, $first->middleware()[0]->expiresAfter);
    }

    public function test_a_new_strategy_can_be_approved_while_existing_platform_settings_are_reserved_unchanged(): void
    {
        $this->campaign->update(['keywords' => null]);
        $this->strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $second = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'daily_budget' => '5.00', 'bidding_strategy' => ['name' => 'MaximizeConversions']]);
        $oldBidding = $this->strategy->fresh()->bidding_strategy;
        $this->proposal['strategies'] = [[
            'strategy_id' => $this->strategy->id, 'status' => 'preserved', 'budget_micros' => 5_000_000,
            'cpc_bid_micros' => null, 'forecast' => null,
            'current_keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT']],
            'proposed_keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT']],
        ], [...$this->proposal['strategies'][0], 'strategy_id' => $second->id, 'budget_micros' => 5_000_000]];
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertSame($oldBidding, $this->strategy->fresh()->bidding_strategy);
        $this->assertEquals('5.00', $this->strategy->fresh()->daily_budget);
        $this->assertSame('customers/123/campaigns/61', $this->strategy->fresh()->google_ads_campaign_id);
        $this->assertSame('relevant researched keyword', $second->fresh()->bidding_strategy['keywords'][0]['text']);
        $this->workflow->assertApproved($this->campaign);
    }

    public function test_preserved_non_google_market_keywords_do_not_block_a_new_search_proposal(): void
    {
        $facebook = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Facebook Ads',
            'campaign_type' => 'display', 'daily_budget' => '5.00', 'execution_result' => ['platform_ids' => ['campaign' => 'facebook-61']]]);
        $this->proposal['strategies'][0]['budget_micros'] = 5_000_000;
        $this->proposal['strategies'][] = ['strategy_id' => $facebook->id, 'status' => 'preserved', 'budget_micros' => 5_000_000,
            'cpc_bid_micros' => null, 'forecast' => null, 'current_keywords' => $this->campaign->keywords,
            'proposed_keywords' => $this->campaign->keywords];
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertEquals('5.00', $facebook->fresh()->daily_budget);
        $this->assertSame(['platform_ids' => ['campaign' => 'facebook-61']], $facebook->fresh()->execution_result);
        $this->workflow->assertApproved($this->campaign);
    }

    public function test_accepting_lower_keyword_limits_migrates_a_looser_shared_draft_override(): void
    {
        $this->campaign->update(['keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT', 'cpc_bid_micros' => 3_000_000]]]);
        $second = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'daily_budget' => '5.00']);
        $this->proposal['strategies'][0] = [...$this->proposal['strategies'][0], 'budget_micros' => 5_000_000, 'cpc_bid_micros' => 1_000_000,
            'proposed_keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT', 'cpc_bid_micros' => 1_000_000]]];
        $this->proposal['strategies'][] = [...$this->proposal['strategies'][0], 'strategy_id' => $second->id];
        $status = $this->researched();
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertNull($this->campaign->fresh()->keywords);
        $builder = new SearchKeywordBuilder($this->customer);
        foreach ([$this->strategy, $second] as $strategy) {
            $selected = $builder->getKeywords($this->campaign->fresh(), $strategy->fresh(), new ExecutionPlan([]));
            $this->assertSame(1_000_000, $selected[0]['cpc_bid_micros']);
        }
        $this->assertSame(3_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
    }

    public static function preservedKeywordLimits(): array
    {
        return ['different lower limit' => [1_000_000, false], 'equivalent integer string' => ['3000000', true]];
    }

    #[DataProvider('preservedKeywordLimits')]
    public function test_preserved_search_keyword_limits_must_match_actual_reviewed_limits(int|string $limit, bool $accepted): void
    {
        $this->campaign->update(['keywords' => [['text' => 'old keyword', 'match_type' => 'EXACT', 'cpc_bid_micros' => 3_000_000]]]);
        $this->strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $facebook = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Facebook Ads',
            'campaign_type' => 'display', 'daily_budget' => '5.00']);
        $keywords = [['text' => 'old keyword', 'match_type' => 'EXACT', 'cpc_bid_micros' => $limit]];
        $this->proposal['strategies'] = [['strategy_id' => $this->strategy->id, 'status' => 'preserved', 'budget_micros' => 5_000_000,
            'cpc_bid_micros' => null, 'forecast' => null, 'current_keywords' => $keywords, 'proposed_keywords' => $keywords],
            ['strategy_id' => $facebook->id, 'status' => 'ready', 'budget_micros' => 5_000_000]];
        $status = $this->researched();
        $response = $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true]);
        if ($accepted) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertSessionHasErrors('daily_budget');
            $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        }
        $this->assertSame(3_000_000, $this->campaign->fresh()->keywords[0]['cpc_bid_micros']);
        $this->assertEquals('5.00', $this->strategy->fresh()->daily_budget);
    }

    public function test_status_and_refresh_enforce_customer_scope_and_do_not_change_campaign_budget(): void
    {
        $other = Campaign::factory()->create();
        $this->getJson(route('campaigns.market-research.status', $other))->assertNotFound();
        $this->post(route('campaigns.market-research.refresh', $other))->assertNotFound();
        $this->post(route('campaigns.market-research.refresh', $this->campaign))->assertRedirect();
        $this->getJson(route('campaigns.market-research.status', $this->campaign))->assertOk()
            ->assertJsonPath('marketResearch.status', 'queued')->assertJsonPath('marketResearch.approvable', false);
        $this->assertEquals('5.00', $this->campaign->fresh()->daily_budget);
    }
}
