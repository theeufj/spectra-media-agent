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
            'requested_daily_budget_micros' => $budget,
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

    private function researchedOptions(array $bids = [2_000_000, 2_000_000, 2_000_000], ?int $reference = null): array
    {
        $this->customer->update(['currency_code' => 'AUD']);
        if ($reference !== null) {
            $queued = $this->workflow->request($this->campaign, dailyCents: intdiv($reference, 10_000));
            $this->workflow->run($this->campaign, $queued['request_id']);
            $status = $this->workflow->status($this->campaign);
        } else {
            $status = $this->researched();
        }
        $base = $status['result'];
        if ($reference !== null) {
            $base['requested_daily_budget_micros'] = $base['reference_daily_budget_micros'] = $reference;
        }
        $options = [];
        foreach ($reference === null ? [5_000_000, 10_000_000, 15_000_000] : [intdiv($reference, 2), $reference, $reference * 2] as $index => $budget) {
            $row = $base['strategies'][0];
            $bid = $bids[$index];
            $row['cpc_bid_micros'] = $bid;
            $input = ['geo_target_constants' => ['geoTargetConstants/2036'], 'language_constants' => [], 'negative_keywords' => []];
            $row['budget_micros'] = $budget;
            $row['currency_code'] = 'AUD';
            $row['time_zone'] = 'Australia/Sydney';
            $row['evidence']['input_context'] = $input;
            $from = \Carbon\CarbonImmutable::now('Australia/Sydney')->addDay()->startOfDay();
            $forecast = ['success' => true, 'source' => 'google_keyword_forecast', 'checked_at' => now()->toIso8601String(),
                'clicks' => [53, 98, 100][$index], 'cost_micros' => [145_000_000, 266_000_000, 269_000_000][$index],
                'period_days' => 30, 'forecast_period' => ['from' => $from->toDateString(), 'through' => $from->addDays(29)->toDateString()],
                'context' => [...$input, 'bidding_strategy' => 'MANUAL_CPC', 'daily_budget_micros' => $budget,
                    'cpc_bid_ceiling_micros' => $bid, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney',
                    'keywords' => array_map(fn ($keyword) => [...$keyword, 'cpc_bid_micros' => $bid], $row['proposed_keywords'])]];
            $row['forecast'] = $forecast;
            $proposal = [...$base, 'strategies' => [$row], 'proposed_daily_budget_micros' => $budget];
            $options[] = ['id' => hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)), 'daily_budget_micros' => $budget,
                'cpc_bid_micros' => $bid, 'forecast' => $forecast, 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'proposal' => $proposal];
        }
        $default = $reference === null ? $options[0] : $options[1];
        $result = [...$default['proposal'], 'budget_options' => $options,
            'default_budget_option_id' => $default['id'], 'selected_budget_option_id' => $default['id']];
        $stored = $this->campaign->fresh()->search_delivery_state;
        $stored['market_research']['result'] = $result;
        $this->campaign->update(['search_delivery_state' => $stored]);

        return $this->workflow->status($this->campaign);
    }

    public function test_a_custom_reference_does_not_prevent_an_explicitly_chosen_higher_comparison_or_approve_it_implicitly(): void
    {
        $status = $this->researchedOptions(reference: 50_000_000);
        $options = $status['result']['budget_options'];
        $this->assertSame($options[1]['id'], $status['result']['default_budget_option_id']);
        $this->assertEquals('5.00', $this->campaign->fresh()->daily_budget);
        $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        $body = ['daily_budget' => '100.00', 'research_id' => $status['request_id'],
            'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true];
        $this->post(route('campaigns.confirm-budget', $this->campaign), [...$body, 'budget_option_id' => $options[1]['id']])
            ->assertSessionHasErrors('daily_budget');
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->post(route('campaigns.confirm-budget', $this->campaign), [...$body, 'budget_option_id' => $options[2]['id']])
            ->assertSessionHasNoErrors();
        $this->assertEquals('100.00', $this->campaign->fresh()->approved_daily_budget);
        $this->assertEquals('100.00', $this->strategy->fresh()->daily_budget);
        $this->assertSame($options[2]['id'], $this->workflow->status($this->campaign)['result']['selected_budget_option_id']);
    }

    public function test_positive_fractional_low_reach_is_approvable_but_zero_and_unavailable_proof_are_not(): void
    {
        $status = $this->researchedOptions();
        $state = $this->campaign->fresh()->search_delivery_state;
        $result = $state['market_research']['result'];
        $option = $result['budget_options'][0];
        $option['forecast']['clicks'] = 0.25;
        $option['forecast']['cost_micros'] = 100_000;
        $option['proposal']['strategies'][0]['forecast'] = $option['forecast'];
        $option['proposal']['strategies'][0]['traffic_status'] = 'limited';
        $option['proposal']['strategies'][0]['meets_traffic_threshold'] = false;
        $option['id'] = hash('sha256', json_encode($option['proposal'], JSON_THROW_ON_ERROR));
        $state['market_research']['result'] = [...$option['proposal'], 'budget_options' => [$option],
            'default_budget_option_id' => null, 'selected_budget_option_id' => null, 'requires_explicit_option_selection' => true];
        $this->campaign->update(['search_delivery_state' => $state]);
        $body = ['daily_budget' => '5.00', 'research_id' => $status['request_id'],
            'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true];
        $this->post(route('campaigns.confirm-budget', $this->campaign), $body)->assertSessionHasErrors('daily_budget');
        foreach ([0, null] as $invalidClicks) {
            $invalid = $option;
            $invalid['forecast']['clicks'] = $invalidClicks;
            $invalid['proposal']['strategies'][0]['forecast'] = $invalid['forecast'];
            $invalid['id'] = hash('sha256', json_encode($invalid['proposal'], JSON_THROW_ON_ERROR));
            $bad = $state;
            $bad['market_research']['result']['budget_options'] = [$invalid];
            $this->campaign->update(['search_delivery_state' => $bad]);
            $this->post(route('campaigns.confirm-budget', $this->campaign), [...$body, 'budget_option_id' => $invalid['id']])
                ->assertSessionHasErrors('daily_budget');
            $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        }
        $this->campaign->update(['search_delivery_state' => $state]);
        $this->post(route('campaigns.confirm-budget', $this->campaign), [...$body, 'budget_option_id' => $option['id']])
            ->assertSessionHasNoErrors();
        $saved = $this->workflow->status($this->campaign);
        $this->assertTrue($saved['approved']);
        $this->assertSame(0.25, $saved['result']['strategies'][0]['forecast']['clicks']);
        $this->assertSame('limited', $saved['result']['strategies'][0]['traffic_status']);
    }

    public function test_approval_adopts_only_the_selected_budget_option_and_persists_its_actual_forecast(): void
    {
        $status = $this->researchedOptions();
        $selected = $status['result']['budget_options'][1];
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00', 'budget_option_id' => $selected['id'],
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertEquals('10.00', $this->campaign->fresh()->approved_daily_budget);
        $this->assertEquals('10.00', $this->strategy->fresh()->daily_budget);
        $approved = $this->workflow->status($this->campaign);
        $this->assertTrue($approved['approved']);
        $this->assertSame($selected['id'], $approved['result']['selected_budget_option_id']);
        $this->assertSame(10_000_000, $approved['result']['proposed_daily_budget_micros']);
        $this->assertSame(98, $approved['result']['strategies'][0]['forecast']['clicks']);
        $this->assertCount(3, $approved['result']['budget_options']);
        $this->assertSame(3_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        $this->workflow->assertApproved($this->campaign);
    }

    public function test_approval_adopts_the_selected_options_own_researched_cpc_within_the_owner_limit(): void
    {
        $status = $this->researchedOptions([1_000_000, 2_500_000, 3_000_000]);
        $option = $status['result']['budget_options'][1];
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '10.00', 'budget_option_id' => $option['id'],
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true])->assertSessionHasNoErrors();
        $this->assertSame(2_500_000, $this->strategy->fresh()->bidding_strategy['parameters']['initialCpcBidMicros']);
        $this->assertSame(3_000_000, $this->strategy->fresh()->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        $approved = $this->workflow->status($this->campaign);
        $this->assertSame(2_500_000, $approved['result']['strategies'][0]['cpc_bid_micros']);
        $this->assertSame(2_500_000, $approved['result']['strategies'][0]['forecast']['context']['cpc_bid_ceiling_micros']);
        $this->assertSame(98, $approved['result']['strategies'][0]['forecast']['clicks']);
        $this->assertEquals('10.00', $this->campaign->fresh()->approved_daily_budget);
        $this->assertTrue($approved['approved']);
        $this->workflow->assertApproved($this->campaign);
    }

    public static function invalidBudgetOptions(): array
    {
        return array_map(fn ($case) => [$case], ['unknown_id', 'wrong_amount', 'expired', 'wrong_request', 'changed_inputs',
            'failed_forecast', 'zero_clicks', 'missing_cost', 'overspend', 'different_keywords', 'different_keyword_bid', 'past_dates', 'stale_forecast',
            'live_review', 'platform_footprint', 'custom_budget_mismatch', 'different_proposed_selection', 'option_over_owner_cap']);
    }

    #[DataProvider('invalidBudgetOptions')]
    public function test_unavailable_stale_unviable_or_nonapproved_options_cannot_authorize_a_daily_budget(string $case): void
    {
        $status = $this->researchedOptions();
        $stored = $this->campaign->fresh()->search_delivery_state;
        $option = $stored['market_research']['result']['budget_options'][1];
        $body = ['daily_budget' => '10.00', 'budget_option_id' => $option['id'],
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'], 'accept_proposal' => true];
        if ($case === 'unknown_id') {
            $body['budget_option_id'] = str_repeat('a', 64);
        } elseif ($case === 'wrong_amount') {
            $body['daily_budget'] = '15.00';
        } elseif ($case === 'expired') {
            $this->travel(16)->minutes();
        } elseif ($case === 'wrong_request') {
            $body['research_id'] = (string) \Illuminate\Support\Str::uuid();
        } elseif ($case === 'changed_inputs') {
            $this->strategy->update(['daily_budget' => '6.00']);
        } elseif ($case === 'live_review') {
            $stored['market_research']['status'] = $stored['market_research']['result']['status'] = 'needs_review';
        } elseif ($case === 'platform_footprint') {
            $this->strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        } elseif ($case === 'custom_budget_mismatch') {
            $stored['market_research']['result']['requested_daily_budget_micros'] = 5_000_000;
        } else {
            $forecast = $option['forecast'];
            match ($case) {
                'failed_forecast' => $forecast['success'] = false,
                'zero_clicks' => $forecast['clicks'] = 0,
                'missing_cost' => $forecast['cost_micros'] = null,
                'overspend' => $forecast['cost_micros'] = 300_000_001,
                'different_keywords' => $forecast['context']['keywords'][0]['text'] = 'unresearched traffic',
                'different_keyword_bid' => $forecast['context']['keywords'][0]['cpc_bid_micros'] = 1_000_000,
                'past_dates' => $forecast['forecast_period']['from'] = now()->subDay()->toDateString(),
                'stale_forecast' => $forecast['checked_at'] = now()->subMinutes(16)->toIso8601String(),
                'different_proposed_selection' => $forecast['context']['keywords'][0]['text'] = 'other proposed service',
                'option_over_owner_cap' => $forecast['context']['cpc_bid_ceiling_micros'] = 4_000_000,
                default => throw new \LogicException('Unhandled option case'),
            };
            if ($case === 'different_proposed_selection') {
                $option['proposal']['strategies'][0]['proposed_keywords'][0]['text'] = 'other proposed service';
            } elseif ($case === 'option_over_owner_cap') {
                $option['cpc_bid_micros'] = $option['proposal']['strategies'][0]['cpc_bid_micros'] = 4_000_000;
                $forecast['context']['keywords'][0]['cpc_bid_micros'] = 4_000_000;
            }
            $option['forecast'] = $option['proposal']['strategies'][0]['forecast'] = $forecast;
            $option['id'] = hash('sha256', json_encode($option['proposal'], JSON_THROW_ON_ERROR));
            $body['budget_option_id'] = $option['id'];
            $stored['market_research']['result']['budget_options'][1] = $option;
        }
        $this->campaign->update(['search_delivery_state' => $stored]);
        $this->post(route('campaigns.confirm-budget', $this->campaign), $body)->assertSessionHasErrors('daily_budget');
        $this->assertNull($this->campaign->fresh()->approved_daily_budget);
        $this->assertNull($this->campaign->fresh()->budget_confirmed_at);
        $this->assertEquals($case === 'changed_inputs' ? '6.00' : '5.00', $this->strategy->fresh()->daily_budget);
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

    public function test_existing_search_can_research_fifty_per_day_but_cannot_apply_it_through_draft_approval(): void
    {
        $this->customer->update(['currency_code' => 'AUD']);
        $this->campaign->update(['approved_daily_budget' => '5.00', 'budget_confirmed_at' => now(), 'status' => 'paused']);
        $this->strategy->recordGoogleCampaignId('customers/123/campaigns/61');
        $fields = array_flip(['daily_budget', 'approved_daily_budget', 'budget_confirmed_at', 'status', 'keywords']);
        $before = [array_intersect_key($this->campaign->fresh()->getAttributes(), $fields),
            $this->strategy->fresh()->getAttributes()];
        $planner = $this->createMock(\App\Services\GoogleAds\InitialSearchBidPlanner::class);
        $planner->expects($this->exactly(3))->method('estimate')->willReturnCallback(function ($campaign, $keywords, $context, $budget, $cap): array {
            $this->assertContains($budget, [25_000_000, 50_000_000, 100_000_000]);
            $this->assertSame(3_000_000, $cap);

            return ['ready' => true, 'cpc_bid_micros' => 2_000_000, 'caveats' => [], 'evidence' => ['historical' => [],
                'forecast' => ['success' => true, 'clicks' => 40, 'cost_micros' => 100_000_000, 'period_days' => 30,
                    'context' => ['daily_budget_micros' => $budget],
                    'forecast_period' => ['from' => now()->addDay()->toDateString(), 'through' => now()->addDays(30)->toDateString()]]]];
        });
        $planner->expects($this->never())->method('forecastAtBid');
        $research = new class($planner) extends CampaignMarketResearch
        {
            public function __construct(private \App\Services\GoogleAds\InitialSearchBidPlanner $planner) {}

            protected function accountContext(Customer $customer): array
            {
                return ['customer_id' => '123', 'currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true];
            }

            protected function inputs(Campaign $campaign, Strategy $strategy): array
            {
                return ['keywords' => $campaign->keywords, 'geo_target_constants' => ['geoTargetConstants/2036'],
                    'language_constants' => [], 'negative_keywords' => [], 'unsupported_targeting' => []];
            }

            protected function businessResearch(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
            {
                return ['keywords' => $selected];
            }

            protected function bidPlanner(): \App\Services\GoogleAds\InitialSearchBidPlanner
            {
                return $this->planner;
            }
        };
        $workflow = new CampaignMarketResearchWorkflow($research);
        $this->app->instance(CampaignMarketResearchWorkflow::class, $workflow);
        $this->post(route('campaigns.market-research.refresh', $this->campaign), ['daily_budget' => '50.00'])->assertSessionHasNoErrors();
        $queued = $workflow->status($this->campaign);
        $workflow->run($this->campaign, $queued['request_id']);
        $status = $workflow->status($this->campaign);
        $this->assertSame(50_000_000, $status['requested_daily_budget_micros']);
        $this->assertSame(50_000_000, $status['result']['proposed_daily_budget_micros']);
        $this->assertSame(50_000_000, $status['result']['strategies'][0]['forecast']['context']['daily_budget_micros']);
        $this->assertFalse($status['approvable']);
        $this->assertTrue($status['result']['strategies'][0]['requires_live_review']);
        $this->assertCount(3, $status['result']['budget_options']);
        $this->post(route('campaigns.confirm-budget', $this->campaign), ['daily_budget' => '50.00',
            'research_id' => $status['request_id'], 'research_fingerprint' => $status['input_fingerprint'],
            'budget_option_id' => $status['result']['budget_options'][0]['id'], 'accept_proposal' => true])->assertSessionHasErrors('daily_budget');
        $this->assertSame($before, [array_intersect_key($this->campaign->fresh()->getAttributes(), $fields),
            $this->strategy->fresh()->getAttributes()]);
        Queue::assertNotPushed(\App\Jobs\DeployCampaign::class);
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
