<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ExceptionLog;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use App\Services\GoogleAds\ReconcileSearchAudienceObservation;
use Google\Ads\GoogleAds\V22\Common\TargetRestrictionOperation\Operator;
use Google\Ads\GoogleAds\V22\Enums\TargetingDimensionEnum\TargetingDimension;
use Google\Ads\GoogleAds\V22\Services\MutateOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Mockery;
use Tests\TestCase;

class GoogleSearchAudienceObservationTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private Campaign $campaign;

    private Strategy $strategy;

    private SearchAudienceObservationFixture $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::set('managed_billing_enabled', false, 'boolean');
        $this->customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'status' => 'active',
            'platform_status' => 'ENABLED', 'google_ads_campaign_id' => 'customers/123/campaigns/9']);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Search',
            'google_ads_campaign_id' => 'customers/123/campaigns/9', 'google_ads_ad_group_id' => 'customers/123/adGroups/10',
            'signed_off_at' => now(), 'execution_result' => ['metadata' => ['untouched' => true]]]);
        Feature::for($this->customer)->activate(AutoHealing::class);
        $this->service = new SearchAudienceObservationFixture($this->customer);
    }

    private function expectMockCall(\Mockery\MockInterface $mock, string $method): \Mockery\Expectation
    {
        $mock->shouldReceive($method);
        $expectations = $mock->mockery_getExpectationsFor($method)?->getExpectations() ?? [];
        $expectation = array_pop($expectations);
        if (! $expectation instanceof \Mockery\Expectation) {
            throw new \LogicException('A named mock method must return a concrete expectation.');
        }

        return $expectation;
    }

    public function test_inspect_detects_implicit_targeting_with_actual_audiences_without_writes(): void
    {
        $state = $this->service->inspect('customers/123/campaigns/9');

        $this->assertSame('needs_review', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertSame('search_audience_restricts_reach', $state['issues'][0]->code);
        $this->assertSame(4, $state['ad_groups'][0]['audience_criteria_count']);
        $this->assertSame([], $this->service->operations);
        $this->assertSame(['metadata' => ['untouched' => true]], $this->strategy->fresh()->execution_result);
    }

    public function test_reconcile_preserves_all_other_restrictions_and_only_updates_targeting_then_verifies(): void
    {
        $before = $this->campaign->fresh()->getRawOriginal();
        $restrictions = $this->service->group['targetingSetting']['targetRestrictions'];
        $state = $this->service->reconcile('customers/123/campaigns/9');

        $this->assertTrue($state['ready']);
        $this->assertTrue($state['ad_groups'][0]['observation']);
        $this->assertCount(1, $this->service->operations);
        $operation = $this->service->operations[0][0];
        $this->assertTrue($operation->hasAdGroupOperation());
        $this->assertFalse($operation->hasCampaignOperation());
        $update = $operation->getAdGroupOperation()->getUpdate();
        $serialized = json_decode($update->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['resourceName', 'targetingSetting'], array_keys($serialized));
        $this->assertSame(['targeting_setting.target_restriction_operations'], iterator_to_array($operation->getAdGroupOperation()->getUpdateMask()->getPaths()));
        $changes = $update->getTargetingSetting()->getTargetRestrictionOperations();
        $this->assertCount(1, $changes);
        $this->assertSame(Operator::ADD, $changes[0]->getOperator());
        $this->assertSame(TargetingDimension::AUDIENCE, $changes[0]->getValue()->getTargetingDimension());
        $this->assertTrue($changes[0]->getValue()->getBidOnly());
        $this->assertCount(0, $update->getTargetingSetting()->getTargetRestrictions());
        $this->assertSame([...$restrictions, ['targetingDimension' => 'AUDIENCE', 'bidOnly' => true]], $this->service->group['targetingSetting']['targetRestrictions']);
        $this->assertSame($before, $this->campaign->fresh()->getRawOriginal());
        $this->assertSame(['metadata' => ['untouched' => true]], $this->strategy->fresh()->execution_result);
        $this->assertTrue($this->service->reconcile('customers/123/campaigns/9')['ready']);
        $this->assertCount(1, $this->service->operations);
    }

    public function test_campaign_level_restrictions_are_preserved_and_updated_without_conflicting_ad_group_setting(): void
    {
        $this->service->remoteCampaign['targetingSetting'] = ['targetRestrictions' => [
            ['targetingDimension' => 'AGE_RANGE', 'bidOnly' => true],
            ['targetingDimension' => 'GENDER'],
            ['targetingDimension' => 'AUDIENCE'],
        ]];
        $before = $this->service->group;

        $state = $this->service->reconcile('customers/123/campaigns/9');

        $this->assertTrue($state['ready']);
        $this->assertSame('campaign', $state['ad_groups'][0]['restriction_source']);
        $operation = $this->service->operations[0][0];
        $this->assertTrue($operation->hasCampaignOperation());
        $this->assertSame(['targeting_setting.target_restriction_operations'], iterator_to_array($operation->getCampaignOperation()->getUpdateMask()->getPaths()));
        $this->assertSame($before, $this->service->group);
        $this->assertSame([['targetingDimension' => 'AGE_RANGE', 'bidOnly' => true], ['targetingDimension' => 'GENDER'],
            ['targetingDimension' => 'AUDIENCE', 'bidOnly' => true]], $this->service->remoteCampaign['targetingSetting']['targetRestrictions']);
    }

    public function test_no_positive_audiences_does_not_claim_omitted_flag_restricts_reach(): void
    {
        $this->service->criteria = [];
        $state = $this->service->reconcile('customers/123/campaigns/9');

        $this->assertSame('not_applicable', $state['status']);
        $this->assertTrue($state['ready']);
        $this->assertFalse($state['applicable']);
        $this->assertSame([], $state['issues']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_negative_audience_criteria_are_not_treated_as_positive_reach_filters(): void
    {
        foreach ($this->service->criteria as &$criterion) {
            $criterion['adGroupCriterion']['negative'] = true;
        }
        unset($criterion);

        $this->assertSame('not_applicable', $this->service->inspect('customers/123/campaigns/9')['status']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_unknown_and_other_account_targets_never_trigger_api_or_writes(): void
    {
        foreach ([null, 'customers/999/campaigns/9', 'customers/123/campaigns/9 OR 1=1'] as $resource) {
            $state = $this->service->reconcile($resource);
            $this->assertSame('unknown', $state['status']);
            $this->assertFalse($state['ready']);
        }
        $this->assertSame('unknown', $this->service->ensureForAdGroup('customers/999/adGroups/10', 'customers/123/campaigns/9')['status']);
        $this->assertSame(0, $this->service->readCount);
        $this->assertSame([], $this->service->operations);
    }

    public function test_same_account_group_from_another_campaign_is_rejected_before_configuration(): void
    {
        $this->service->remoteCampaign['resourceName'] = 'customers/123/campaigns/99';

        $state = $this->service->ensureForAdGroup('customers/123/adGroups/10', 'customers/123/campaigns/9');

        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertSame('search_audience_campaign_mismatch', $state['issues'][0]->code);
        $this->assertSame(1, $this->service->readCount);
        $this->assertSame([], $this->service->operations);
    }

    public function test_non_search_and_unknown_channel_types_never_change_targeting(): void
    {
        $this->service->remoteCampaign['advertisingChannelType'] = 'DISPLAY';
        $state = $this->service->reconcile('customers/123/campaigns/9');
        $this->assertSame('not_applicable', $state['status']);
        $this->assertFalse($state['applicable']);
        $this->service->remoteCampaign['advertisingChannelType'] = 'UNKNOWN';
        $this->assertSame('unknown', $this->service->reconcile('customers/123/campaigns/9')['status']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_paused_remote_campaign_is_never_resumed_or_reconfigured(): void
    {
        $this->service->remoteCampaign['status'] = 'PAUSED';
        $state = $this->service->reconcile('customers/123/campaigns/9');

        $this->assertFalse($state['ready']);
        $this->assertSame('google_campaign_inactive', $state['reason']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_remote_pause_during_reads_blocks_the_write(): void
    {
        $this->service->beforeCampaignRead = function (SearchAudienceObservationFixture $service): void {
            if ($service->campaignReads === 2) {
                $service->remoteCampaign['status'] = 'PAUSED';
            }
        };

        $this->assertFalse($this->service->reconcile('customers/123/campaigns/9')['ready']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_manual_pause_during_google_reads_rechecks_fresh_local_hold(): void
    {
        $this->service->beforeCampaignRead = function (SearchAudienceObservationFixture $service): void {
            if ($service->campaignReads === 2) {
                $this->campaign->fresh()->update(['status' => 'paused']);
            }
        };

        $state = $this->service->reconcile('customers/123/campaigns/9');
        $this->assertFalse($state['ready']);
        $this->assertSame('campaign_not_active', $state['reason']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_automation_flag_change_during_reads_blocks_the_write(): void
    {
        $this->service->beforeCampaignRead = function (SearchAudienceObservationFixture $service): void {
            if ($service->campaignReads === 2) {
                Feature::for($this->customer)->deactivate(AutoHealing::class);
            }
        };

        $state = $this->service->reconcile('customers/123/campaigns/9');
        $this->assertSame('auto_healing_disabled', $state['reason']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_billing_hold_changed_during_reads_blocks_the_write(): void
    {
        $credit = AdSpendCredit::factory()->create(['customer_id' => $this->customer->id, 'current_balance' => '50.00', 'status' => 'active', 'payment_status' => 'current']);
        Setting::set('managed_billing_enabled', true, 'boolean');
        $this->service->beforeCampaignRead = function (SearchAudienceObservationFixture $service) use ($credit): void {
            if ($service->campaignReads === 2) {
                $credit->fresh()->update(['status' => 'suspended']);
            }
        };

        $this->assertSame('ad_spend_unfunded', $this->service->reconcile('customers/123/campaigns/9')['reason']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_explicit_audience_only_intent_is_not_silently_broadened(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'google_options' => ['audience_mode' => 'targeting']]);

        $state = $this->service->reconcile('customers/123/campaigns/9');
        $this->assertSame('not_applicable', $state['status']);
        $this->assertSame('explicit_audience_targeting_intent', $state['reason']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_explicit_targeting_intent_with_observation_requires_review_without_narrowing(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'google_options' => ['audience_mode' => 'targeting']]);
        $this->service->group['targetingSetting']['targetRestrictions'][] = ['targetingDimension' => 'AUDIENCE', 'bidOnly' => true];

        foreach ([$this->service->inspect('customers/123/campaigns/9'), $this->service->reconcile('customers/123/campaigns/9')] as $state) {
            $this->assertSame('needs_review', $state['status']);
            $this->assertFalse($state['ready']);
            $this->assertSame('explicit_audience_targeting_mismatch', $state['issues'][0]->code);
        }
        // Before attaching the first audience, the creation path also checks intent.
        $this->service->criteria = [];
        $state = $this->service->ensureForAdGroup('customers/123/adGroups/10', 'customers/123/campaigns/9');
        $this->assertSame('explicit_audience_targeting_mismatch', $state['issues'][0]->code);
        $this->assertSame([], $this->service->operations);
    }

    public function test_creation_sets_explicit_observation_before_any_signals_in_paused_staging(): void
    {
        $this->service->criteria = [];
        $this->service->remoteCampaign['status'] = 'PAUSED';
        $this->service->group['status'] = 'PAUSED';

        $state = $this->service->ensureForAdGroup('customers/123/adGroups/10', 'customers/123/campaigns/9');

        $this->assertTrue($state['ready']);
        $this->assertSame('PAUSED', $this->service->remoteCampaign['status']);
        $this->assertSame('PAUSED', $this->service->group['status']);
        $this->assertSame(0, $state['ad_groups'][0]['audience_criteria_count']);
        $this->assertTrue($state['ad_groups'][0]['observation']);
        $this->assertCount(1, $this->service->operations);
    }

    public function test_google_write_acceptance_without_readback_confirmation_is_unverified(): void
    {
        $this->service->applyUpdates = false;

        $state = $this->service->reconcile('customers/123/campaigns/9');
        $this->assertFalse($state['ready']);
        $this->assertSame('search_audience_observation_unverified', $state['issues'][0]->code);
        $this->assertCount(1, $state['actions']);
    }

    public function test_incremental_write_does_not_overwrite_a_concurrent_non_audience_change(): void
    {
        $this->service->beforeApply = static function (SearchAudienceObservationFixture $service): void {
            $service->group['targetingSetting']['targetRestrictions'][1] = ['targetingDimension' => 'GENDER'];
        };

        $state = $this->service->reconcile('customers/123/campaigns/9');

        $this->assertSame(['targetingDimension' => 'GENDER'], $this->service->group['targetingSetting']['targetRestrictions'][1]);
        $this->assertTrue($state['ad_groups'][0]['observation']);
        $this->assertFalse($state['ready']);
        $this->assertSame('search_audience_observation_unverified', $state['issues'][0]->code);
    }

    public function test_type_error_is_reported_and_unknown_without_exposing_provider_text(): void
    {
        $this->service->beforeCampaignRead = static fn () => throw new \TypeError('Provider response with private details');

        $state = $this->service->inspect('customers/123/campaigns/9');
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertStringNotContainsString('private details', $state['issues'][0]->message);
        $this->assertTrue(ExceptionLog::where('type', \TypeError::class)->where('message', 'Provider response with private details')->exists());
        $this->assertSame([], $this->service->operations);
    }

    public function test_targeter_never_attaches_signals_when_observation_cannot_be_confirmed(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'interests' => ['Business Software']]);
        $service = Mockery::mock(ReconcileSearchAudienceObservation::class);
        $expectation = $this->expectMockCall($service, 'ensureForAdGroup');
        $expectation->once()->with('customers/123/adGroups/10', 'customers/123/campaigns/9')->andReturn([
            'status' => 'unknown', 'ready' => false, 'issues' => [new AgentIssue('search_audience_check_unavailable', 'Observation is unavailable.')],
        ]);
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $service);
        $search = Mockery::mock(SearchAudience::class);
        $search->shouldNotReceive('__invoke');
        $this->app->bind(SearchAudience::class, fn () => $search);
        $add = Mockery::mock(AddAdGroupCriterion::class);
        $add->shouldNotReceive('__invoke');
        $this->app->bind(AddAdGroupCriterion::class, fn () => $add);
        $result = new ExecutionResult(true);

        (new AudienceTargeter($this->customer))->addAudienceTargeting('123', 'customers/123/adGroups/10', $this->strategy->fresh(), $result);

        $this->assertSame('search_audience_check_unavailable', $result->warnings[0]->code);
        $this->assertSame([], $result->platformIds);
    }

    public function test_targeter_confirms_observation_before_adding_an_interest(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'interests' => ['Business Software']]);
        $this->service->criteria = [];
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $this->service);
        $search = Mockery::mock(SearchAudience::class);
        $searchExpectation = $this->expectMockCall($search, '__invoke');
        $searchExpectation->once()->with('123', 'Business Software')->andReturn([['id' => 'customers/123/userInterests/8', 'name' => 'Business Software']]);
        $this->app->bind(SearchAudience::class, fn () => $search);
        $add = Mockery::mock(AddAdGroupCriterion::class);
        $addExpectation = $this->expectMockCall($add, '__invoke');
        $addExpectation->once()->andReturnUsing(function () {
            $this->assertCount(1, $this->service->operations);
            $this->assertTrue($this->service->group['targetingSetting']['targetRestrictions'][4]['bidOnly']);

            return 'customers/123/adGroupCriteria/10~8';
        });
        $this->app->bind(AddAdGroupCriterion::class, fn () => $add);
        $result = new ExecutionResult(true);

        (new AudienceTargeter($this->customer))->addAudienceTargeting('123', 'customers/123/adGroups/10', $this->strategy->fresh(), $result);

        $this->assertSame([], $result->warnings);
        $this->assertSame('customers/123/adGroupCriteria/10~8', $result->platformIds['audience']);
    }

    public function test_targeter_checks_campaign_ownership_for_explicit_targeting_too(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'interests' => ['Business Software'], 'google_options' => ['audience_mode' => 'targeting']]);
        $this->service->remoteCampaign['resourceName'] = 'customers/123/campaigns/99';
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $this->service);
        $search = Mockery::mock(SearchAudience::class);
        $search->shouldNotReceive('__invoke');
        $this->app->bind(SearchAudience::class, fn () => $search);
        $add = Mockery::mock(AddAdGroupCriterion::class);
        $add->shouldNotReceive('__invoke');
        $this->app->bind(AddAdGroupCriterion::class, fn () => $add);
        $result = new ExecutionResult(true);

        (new AudienceTargeter($this->customer))->addAudienceTargeting('123', 'customers/123/adGroups/10', $this->strategy->fresh(), $result);

        $this->assertSame('search_audience_campaign_mismatch', $result->warnings[0]->code);
        $this->assertSame([], $this->service->operations);
        $this->assertSame([], $result->platformIds);
    }

    public function test_targeter_keeps_verified_explicit_targeting_without_broadening_it(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'interests' => ['Business Software'], 'google_options' => ['audience_mode' => 'targeting']]);
        $this->service->criteria = [];
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $this->service);
        $search = Mockery::mock(SearchAudience::class);
        $expectation = $this->expectMockCall($search, '__invoke');
        $expectation->once()->andReturn([['id' => 'customers/123/userInterests/8', 'name' => 'Business Software']]);
        $this->app->bind(SearchAudience::class, fn () => $search);
        $add = Mockery::mock(AddAdGroupCriterion::class);
        $addExpectation = $this->expectMockCall($add, '__invoke');
        $addExpectation->once()->andReturn('customers/123/adGroupCriteria/10~8');
        $this->app->bind(AddAdGroupCriterion::class, fn () => $add);
        $result = new ExecutionResult(true);

        (new AudienceTargeter($this->customer))->addAudienceTargeting('123', 'customers/123/adGroups/10', $this->strategy->fresh(), $result);

        $this->assertSame([], $result->warnings);
        $this->assertSame('explicit_audience_targeting_intent', $result->metadata['search_audience_observation']['reason']);
        $this->assertSame([], $this->service->operations);
        $this->assertSame('customers/123/adGroupCriteria/10~8', $result->platformIds['audience']);
    }

    public function test_targeter_does_not_attach_explicit_targeting_audiences_in_observation_mode(): void
    {
        TargetingConfig::create(['strategy_id' => $this->strategy->id, 'interests' => ['Business Software'], 'google_options' => ['audience_mode' => 'targeting']]);
        $this->service->criteria = [];
        $this->service->group['targetingSetting']['targetRestrictions'][] = ['targetingDimension' => 'AUDIENCE', 'bidOnly' => true];
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $this->service);
        $search = Mockery::mock(SearchAudience::class);
        $search->shouldNotReceive('__invoke');
        $this->app->bind(SearchAudience::class, fn () => $search);
        $add = Mockery::mock(AddAdGroupCriterion::class);
        $add->shouldNotReceive('__invoke');
        $this->app->bind(AddAdGroupCriterion::class, fn () => $add);
        $result = new ExecutionResult(true);

        (new AudienceTargeter($this->customer))->addAudienceTargeting('123', 'customers/123/adGroups/10', $this->strategy->fresh(), $result);

        $this->assertSame('explicit_audience_targeting_mismatch', $result->warnings[0]->code);
        $this->assertSame([], $this->service->operations);
        $this->assertSame([], $result->platformIds);
    }
}

class SearchAudienceObservationFixture extends ReconcileSearchAudienceObservation
{
    public array $remoteCampaign = ['resourceName' => 'customers/123/campaigns/9', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'];

    public array $group = ['resourceName' => 'customers/123/adGroups/10', 'status' => 'ENABLED', 'targetingSetting' => ['targetRestrictions' => [
        ['targetingDimension' => 'AGE_RANGE', 'bidOnly' => true], ['targetingDimension' => 'GENDER', 'bidOnly' => true],
        ['targetingDimension' => 'PARENTAL_STATUS', 'bidOnly' => true], ['targetingDimension' => 'INCOME_RANGE', 'bidOnly' => true],
    ]]];

    public array $criteria = [];

    /** @var list<list<MutateOperation>> */
    public array $operations = [];

    public int $readCount = 0;

    public int $campaignReads = 0;

    public bool $applyUpdates = true;

    public ?\Closure $beforeCampaignRead = null;

    public ?\Closure $beforeApply = null;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->criteria = array_fill(0, 4, ['campaign' => ['resourceName' => $this->remoteCampaign['resourceName']],
            'adGroup' => ['resourceName' => $this->group['resourceName']], 'adGroupCriterion' => ['type' => 'USER_INTEREST', 'status' => 'ENABLED']]);
    }

    protected function readRows(string $customerId, string $query): array
    {
        $this->readCount++;
        if (str_contains($query, 'FROM ad_group_criterion')) {
            return $this->criteria;
        }
        if (str_contains($query, 'FROM ad_group ')) {
            return [['campaign' => $this->remoteCampaign, 'adGroup' => $this->group]];
        }
        if (str_contains($query, 'FROM campaign ')) {
            $this->campaignReads++;
            if ($this->beforeCampaignRead) {
                ($this->beforeCampaignRead)($this);
            }

            return [['campaign' => $this->remoteCampaign]];
        }
        throw new \LogicException('Unexpected API read in audience fixture.');
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        $this->operations[] = $operations;
        if (! $this->applyUpdates) {
            return;
        }
        if ($this->beforeApply) {
            ($this->beforeApply)($this);
        }
        foreach ($operations as $operation) {
            $update = $operation->hasCampaignOperation() ? $operation->getCampaignOperation()->getUpdate() : $operation->getAdGroupOperation()->getUpdate();
            $data = json_decode($update->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['targetingSetting']['targetRestrictionOperations'] as $change) {
                $current = $operation->hasCampaignOperation() ? $this->remoteCampaign['targetingSetting']['targetRestrictions'] ?? []
                    : $this->group['targetingSetting']['targetRestrictions'] ?? [];
                $current = array_values(array_filter($current, fn ($item) => $item['targetingDimension'] !== $change['value']['targetingDimension']));
                $current[] = $change['value'];
                if ($operation->hasCampaignOperation()) {
                    $this->remoteCampaign['targetingSetting']['targetRestrictions'] = $current;
                } else {
                    $this->group['targetingSetting']['targetRestrictions'] = $current;
                }
            }
        }
    }
}
