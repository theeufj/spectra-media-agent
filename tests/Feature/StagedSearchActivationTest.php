<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\CheckGoogleCampaignReadiness;
use App\Jobs\RecordSiteConversion;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Campaigns\SettleDeployedCampaign;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StagedSearchActivationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['campaigns.testing_mode_default' => false, 'campaigns.default_status' => 'ENABLED']);
        Setting::where('key', 'campaign_testing_mode')->delete();
    }

    private function fixture(array $campaignAttributes = [], bool $reachReady = true, bool $enableSucceeds = true): array
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'service_type' => 'managed']);
        $resource = 'customers/1234567890/campaigns/456';
        $campaign = Campaign::factory()->create($campaignAttributes + ['customer_id' => $customer->id, 'status' => 'draft',
            'google_ads_campaign_id' => $resource, 'platform_status' => 'PAUSED', 'end_date' => now()->addDays(30)]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'deployed', 'google_ads_campaign_id' => $resource,
            'execution_result' => ['metadata' => ['google_search_staging' => ['campaign_resource' => $resource,
                'verified_platform_status' => 'PAUSED', 'enable_after_success' => true], 'unrelated_evidence' => 'preserved']]]);
        $builder = new class($reachReady) extends SearchKeywordBuilder
        {
            public int $calls = 0;

            public ?\Closure $duringCheck = null;

            public int $activationCalls = 0;

            public array $activationResults = [];

            public function __construct(private bool $ready) {}

            public function preflightReach(Campaign $campaign, Strategy $strategy, string $customerId, string $campaignResource, ExecutionResult $result): bool
            {
                $this->calls++;
                if ($this->duringCheck) {
                    ($this->duringCheck)($campaign);
                }
                $result->addMetadata('search_reach_preflight', ['gate' => ['ready' => $this->ready, 'status' => $this->ready ? 'ready' : 'blocked']]);
                if (! $this->ready) {
                    $result->addError('search_reach_below_useful_threshold', 'Fresh reach evidence is insufficient.');
                }

                return $this->ready;
            }

            public function confirmActivationProof(Campaign $campaign, string $customerId, string $campaignResource, array $proof, ExecutionResult $result): bool
            {
                $this->activationCalls++;
                $ready = $this->activationResults !== [] ? array_shift($this->activationResults) : true;
                $result->addMetadata('search_reach_activation_check', ['ready' => $ready]);
                if (! $ready) {
                    $result->addError('search_reach_activation_settings_changed', 'Targeting changed after this stage was forecast.');
                }

                return $ready;
            }
        };
        $this->app->bind(SearchKeywordBuilder::class, fn () => $builder);
        $reader = new class($enableSucceeds) extends GetCampaignStatus
        {
            public int $calls = 0;

            public array $sequence = [];

            public ?\Closure $duringRead = null;

            public function __construct(private bool $enabled) {}

            public function __invoke(string $customerId, string $campaignResourceName): ?array
            {
                $this->calls++;
                if ($this->duringRead) {
                    ($this->duringRead)($this->calls, $campaignResourceName);
                }
                if ($this->sequence !== []) {
                    $status = array_shift($this->sequence);

                    return $status === null ? null : ['status' => $status];
                }

                return ['status' => $this->calls === 1 || ! $this->enabled ? GoogleCampaignStatus::PAUSED : GoogleCampaignStatus::ENABLED];
            }
        };
        $this->app->bind(GetCampaignStatus::class, fn () => $reader);
        $writer = new class($enableSucceeds) extends UpdateCampaignStatus
        {
            public array $enabled = [];

            public array $paused = [];

            public array $enableResults = [];

            public bool $pauseSucceeds = true;

            public function __construct(private bool $succeeds) {}

            public function enable(string $customerId, string $campaignResourceName, bool $ownerApprovedRestart = false): array
            {
                $this->enabled[] = [$customerId, $campaignResourceName, $ownerApprovedRestart];

                return ['success' => $this->enableResults !== [] ? array_shift($this->enableResults) : $this->succeeds];
            }

            public function pause(string $customerId, string $campaignResourceName): array
            {
                $this->paused[] = [$customerId, $campaignResourceName];

                return ['success' => $this->pauseSucceeds];
            }
        };
        $settler = new class($writer) extends SettleDeployedCampaign
        {
            public function __construct(private UpdateCampaignStatus $writer) {}

            protected function campaignStatusService(Customer $customer): UpdateCampaignStatus
            {
                return $this->writer;
            }
        };

        return [$campaign->load('customer'), $strategy, $builder, $reader, $writer, $settler];
    }

    public function test_successful_staged_build_is_enabled_only_after_a_fresh_reach_check_and_google_readback(): void
    {
        [$campaign, $strategy, $builder, $reader, $writer, $settler] = $this->fixture();
        $this->assertTrue($settler->settle($campaign));
        $this->assertSame(1, $builder->calls);
        $this->assertSame(1, $builder->activationCalls);
        $this->assertSame(2, $reader->calls);
        $this->assertSame([['1234567890', 'customers/1234567890/campaigns/456', false]], $writer->enabled);
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertSame('ENABLED', $campaign->fresh()->platform_status);
        $this->assertNotNull($campaign->fresh()->last_checked_at);
        Queue::assertPushed(CheckGoogleCampaignReadiness::class, fn ($job) => $job->campaignId === $campaign->id
            && $job->afterActivation === true && $job->afterCommit === true && $job->delay === null);
        $this->assertTrue($strategy->fresh()->execution_result['metadata']['search_reach_activation']['enabled_verified']);
        $this->assertSame('preserved', $strategy->fresh()->execution_result['metadata']['unrelated_evidence']);
        Queue::assertPushed(RecordSiteConversion::class);
        $this->assertFalse($settler->settle($campaign), 'A completed activation is idempotent.');
        $this->assertCount(1, $writer->enabled);
        Queue::assertPushed(CheckGoogleCampaignReadiness::class, 1);
    }

    public function test_owner_pause_during_the_enable_readback_is_preserved_and_compensated_before_status_sync(): void
    {
        [$campaign, $strategy, , $reader, $writer, $settler] = $this->fixture();
        $reader->sequence = [GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::ENABLED, GoogleCampaignStatus::PAUSED];
        $reader->duringRead = function (int $call) use ($campaign): void {
            if ($call === 2) {
                $campaign->fresh()->update(['status' => 'paused', 'platform_status' => 'PAUSED']);
            }
        };
        try {
            $settler->settle($campaign);
            $this->fail('A concurrent owner pause cannot be overwritten by activation status synchronization.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
        $this->assertCount(1, $writer->paused);
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['search_reach_activation']['enabled_verified']);
        Queue::assertNotPushed(CheckGoogleCampaignReadiness::class);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_owner_pause_after_status_sync_cannot_fall_through_to_unstaged_lifecycle_promotion(): void
    {
        [$campaign, , , , $writer, $settler] = $this->fixture();
        $dispatcher = Campaign::getEventDispatcher();
        Campaign::setEventDispatcher(clone $dispatcher);
        $pausedAfterSync = false;
        Campaign::retrieved(function (Campaign $observed) use ($campaign, &$pausedAfterSync): void {
            // The first read of ENABLED is synchronizeEnabledStage's final
            // refresh, after the status-sync transaction has returned.
            if (! $pausedAfterSync && $observed->id === $campaign->id && $observed->platform_status === 'ENABLED') {
                $pausedAfterSync = true;
                $observed->update(['status' => CampaignStatus::Paused, 'platform_status' => 'PAUSED']);
            }
        });
        try {
            $this->assertFalse($settler->settle($campaign));
            $this->assertTrue($pausedAfterSync, 'The pause must occur after a verified ENABLED observation was synchronized.');
            $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
            $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
            $this->assertCount(1, $writer->enabled);
            Queue::assertNotPushed(RecordSiteConversion::class);
        } finally {
            Campaign::setEventDispatcher($dispatcher);
        }
    }

    public function test_a_secondary_stage_does_not_claim_an_unobserved_primary_campaign_is_enabled(): void
    {
        [$campaign, , , , , $settler] = $this->fixture(['google_ads_campaign_id' => 'customers/1234567890/campaigns/789']);
        $settler->settle($campaign);
        $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
        $this->assertNull($campaign->fresh()->last_checked_at);
    }

    public function test_multiple_stages_publish_enabled_status_only_after_every_readback_succeeds(): void
    {
        [$campaign, , , $reader, , $settler] = $this->fixture();
        $resource = 'customers/1234567890/campaigns/789';
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'deployed', 'google_ads_campaign_id' => $resource,
            'execution_result' => ['metadata' => ['google_search_staging' => ['campaign_resource' => $resource,
                'verified_platform_status' => 'PAUSED', 'enable_after_success' => true]]]]);
        $reader->sequence = [GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::PAUSED,
            GoogleCampaignStatus::ENABLED, GoogleCampaignStatus::ENABLED];
        $reader->duringRead = function (int $call) use ($campaign): void {
            if ($call >= 3) {
                $this->assertSame('PAUSED', $campaign->fresh()->platform_status);
            }
        };
        $this->assertTrue($settler->settle($campaign));
        $this->assertSame('ENABLED', $campaign->fresh()->platform_status);
        Queue::assertPushed(CheckGoogleCampaignReadiness::class, 1);
    }

    public function test_failed_fresh_reach_check_preserves_the_pause_and_does_not_record_campaign_live(): void
    {
        [$campaign, $strategy, $builder, , $writer, $settler] = $this->fixture(reachReady: false);
        try {
            $settler->settle($campaign);
            $this->fail('An insufficient fresh forecast must hold activation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('fresh Search reach check did not pass', $e->getMessage());
        }
        $this->assertSame(1, $builder->calls);
        $this->assertSame([], $writer->enabled);
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $evidence = $strategy->fresh()->execution_result['metadata']['search_reach_activation'];
        $this->assertFalse($evidence['enabled_verified']);
        $this->assertSame('search_reach_below_useful_threshold', $evidence['issues'][0]['code']);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedCampaigns')]
    public function test_owner_pauses_holds_and_trials_are_not_silently_restarted(array $attributes): void
    {
        [$campaign, , $builder, $reader, $writer, $settler] = $this->fixture($attributes);
        $previous = $campaign->status;
        $this->assertFalse($settler->settle($campaign));
        $this->assertSame($previous, $campaign->fresh()->status);
        $this->assertSame(0, $builder->calls);
        $this->assertSame(0, $reader->calls);
        $this->assertSame([], $writer->enabled);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public static function protectedCampaigns(): array
    {
        return [['attributes' => ['status' => 'paused']], ['attributes' => ['spend_safety_hold' => ['reason' => 'owner review']]],
            ['attributes' => ['spend_guardrails' => ['enabled' => true]]]];
    }

    public function test_testing_mode_preserves_paused_google_status(): void
    {
        [$campaign, , $builder, $reader, $writer, $settler] = $this->fixture();
        config(['campaigns.testing_mode_default' => true]);
        $this->assertTrue($settler->settle($campaign));
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(0, $builder->calls);
        $this->assertSame(0, $reader->calls);
        $this->assertSame([], $writer->enabled);
    }

    public function test_a_concurrent_owner_pause_wins_over_an_earlier_launch_intent(): void
    {
        [$campaign, , $builder, , $writer, $settler] = $this->fixture();
        $builder->duringCheck = fn ($checked) => $checked->fresh()->update(['status' => 'paused']);
        $this->assertFalse($settler->settle($campaign));
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame([], $writer->enabled);
    }

    public function test_rejected_enable_is_not_recorded_as_live_and_can_be_retried(): void
    {
        [$campaign, $strategy, , , $writer, $settler] = $this->fixture(enableSucceeds: false);
        try {
            $settler->settle($campaign);
            $this->fail('A rejected Google enable must fail settlement.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not verified', $e->getMessage());
        }
        $this->assertCount(1, $writer->enabled);
        $this->assertCount(1, $writer->paused);
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['search_reach_activation']['enabled_verified']);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_successful_enable_with_failed_readback_is_compensated_by_a_verified_pause(): void
    {
        [$campaign, $strategy, , $reader, $writer, $settler] = $this->fixture();
        $reader->sequence = [GoogleCampaignStatus::PAUSED, null, GoogleCampaignStatus::PAUSED];
        try {
            $settler->settle($campaign);
            $this->fail('Missing enable readback must not complete activation.');
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $writer->enabled);
        $this->assertCount(1, $writer->paused);
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertTrue($strategy->fresh()->execution_result['metadata']['search_reach_activation']['compensated_pause_verified']);
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['search_reach_activation']['enabled_verified']);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_partial_multi_stage_activation_pauses_every_attempted_resource_before_rolling_back_lifecycle(): void
    {
        [$campaign, $first, $builder, $reader, $writer, $settler] = $this->fixture();
        $secondResource = 'customers/1234567890/campaigns/789';
        $second = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'deployed', 'google_ads_campaign_id' => $secondResource,
            'execution_result' => ['metadata' => ['google_search_staging' => ['campaign_resource' => $secondResource,
                'verified_platform_status' => 'PAUSED', 'enable_after_success' => true]]]]);
        $writer->enableResults = [true, false];
        $reader->sequence = [GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::ENABLED,
            GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::PAUSED];
        try {
            $settler->settle($campaign);
            $this->fail('A partially enabled group cannot complete settlement.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $builder->calls);
        $this->assertCount(2, $writer->enabled);
        $this->assertSame(['customers/1234567890/campaigns/456', $secondResource], array_column($writer->paused, 1));
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        foreach ([$first, $second] as $strategy) {
            $this->assertTrue($strategy->fresh()->execution_result['metadata']['search_reach_activation']['compensated_pause_verified']);
            $this->assertFalse($strategy->fresh()->execution_result['metadata']['search_reach_activation']['enabled_verified']);
        }
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_unverified_compensation_does_not_hide_possible_live_spend_as_a_draft(): void
    {
        [$campaign, $strategy, , $reader, $writer, $settler] = $this->fixture();
        $reader->sequence = [GoogleCampaignStatus::PAUSED, null, GoogleCampaignStatus::ENABLED];
        $writer->pauseSucceeds = false;
        try {
            $settler->settle($campaign);
            $this->fail('Unverified activation must fail settlement.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
        $this->assertSame('deployment_activation_pause_unverified', $campaign->fresh()->spend_safety_hold['reason']);
        $this->assertSame('needs_review', $campaign->fresh()->search_delivery_state['status']);
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['search_reach_activation']['compensated_pause_verified']);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }

    public function test_changed_second_stage_proof_is_held_and_the_already_enabled_first_stage_is_paused(): void
    {
        [$campaign, $first, $builder, $reader, $writer, $settler] = $this->fixture();
        $secondResource = 'customers/1234567890/campaigns/789';
        $second = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'campaign_type' => 'search', 'deployment_status' => 'deployed', 'google_ads_campaign_id' => $secondResource,
            'execution_result' => ['metadata' => ['google_search_staging' => ['campaign_resource' => $secondResource,
                'verified_platform_status' => 'PAUSED', 'enable_after_success' => true]]]]);
        // A PostgreSQL update moves this tuple after the newer strategy. Force
        // a heap scan so activation cannot accidentally rely on physical order.
        DB::statement('SET LOCAL enable_indexscan = off');
        DB::statement('SET LOCAL enable_bitmapscan = off');
        DB::table('strategies')->where('id', $first->id)->update(['updated_at' => now()->addSecond()]);
        $builder->activationResults = [true, false];
        $reader->sequence = [GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::PAUSED, GoogleCampaignStatus::ENABLED, GoogleCampaignStatus::PAUSED];
        try {
            $settler->settle($campaign);
            $this->fail('Changed targeting must hold the second stage before an enable request.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immediate Search activation check did not pass', $e->getMessage());
        }
        $this->assertSame(2, $builder->calls);
        $this->assertSame(2, $builder->activationCalls);
        $this->assertSame(['customers/1234567890/campaigns/456'], array_column($writer->enabled, 1));
        $this->assertSame(['customers/1234567890/campaigns/456'], array_column($writer->paused, 1));
        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertTrue($first->fresh()->execution_result['metadata']['search_reach_activation']['compensated_pause_verified']);
        $this->assertSame('needs_review', $second->fresh()->execution_result['metadata']['search_reach_activation']['status']);
        $this->assertSame('search_reach_activation_settings_changed', $second->fresh()->execution_result['metadata']['search_reach_activation']['issues'][0]['code']);
        Queue::assertNotPushed(RecordSiteConversion::class);
    }
}
