<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\Deployment\GoogleSearchConfigurationCheck;
use App\Services\GeminiService;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CampaignConversionGoalReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private Campaign $campaign;

    private Strategy $strategy;

    private CampaignGoalsFixture $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        config(['conversions.google_ads_customer_id' => '999']);
        Setting::set('managed_billing_enabled', false, 'boolean');
        $this->customer = Customer::factory()->create(['google_ads_customer_id' => '123']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id,
            'status' => 'active', 'platform_status' => 'ENABLED', 'google_ads_campaign_id' => '9']);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Search',
            'google_ads_campaign_id' => 'customers/123/campaigns/9', 'deployment_status' => 'deploy_unverified',
            'conversion_goals' => ['primary_goal' => 'Purchase'], 'deployed_at' => now(), 'signed_off_at' => now(),
            'execution_result' => ['metadata' => ['google_search_baseline' => ['conversion_category' => 'PURCHASE']]]]);
        $this->service = new CampaignGoalsFixture($this->customer);
    }

    public function test_preflight_validates_intent_before_campaign_writes(): void
    {
        $state = $this->service->prepare($this->strategy);
        $this->assertTrue($state['ready']);
        $this->assertSame(['mode' => 'category', 'category' => 'PURCHASE', 'origin' => 'WEBSITE',
            'action_resource' => 'customers/123/conversionActions/7'], $state['intent']);
        $this->assertSame([], $this->service->operations);
        $this->assertSame(0, $this->service->created);
    }

    public function test_reconciles_only_campaign_category_and_origin_then_verifies_and_is_idempotent(): void
    {
        $actions = $this->service->actions;
        $diagnosis = $this->service->inspect($this->strategy);
        $this->assertTrue($diagnosis['repairable']);
        $this->assertSame([], $this->service->operations);
        $state = $this->service->reconcile($this->strategy);
        $this->assertTrue($state['ready']);
        $this->assertSame('campaign_conversion_goals_updated', $state['actions'][0]['type']);
        $this->assertSame([true, false, false], array_column($this->service->goals, 'biddable'));
        $this->assertSame($actions, $this->service->actions);
        $this->assertSame('ENABLED', $this->campaign->fresh()->platform_status);
        $this->assertSame('active', $this->campaign->fresh()->status->value);
        foreach ($this->service->operations[0] as $operation) {
            $this->assertTrue($operation->hasCampaignConversionGoalOperation());
            $this->assertSame(['biddable'], iterator_to_array($operation->getCampaignConversionGoalOperation()->getUpdateMask()->getPaths()));
        }
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertCount(1, $this->service->operations);
        $this->assertSame($state['intent'], $this->strategy->fresh()->execution_result['metadata']['google_search_baseline']['conversion_goal']);
        $this->assertDatabaseHas('agent_activities', ['campaign_id' => $this->campaign->id,
            'action' => 'conversion_goals_updated', 'status' => 'completed']);
    }

    public function test_ambiguous_or_missing_intent_is_durable_review_without_guessing(): void
    {
        $this->service->actions[] = array_merge($this->service->actions[0], ['resourceName' => 'customers/123/conversionActions/8']);
        $first = $this->service->prepare($this->strategy);
        $this->assertFalse($first['ready']);
        $this->assertSame('conversion_goal_primary_ambiguous', $first['issues'][0]->code);
        $this->service->prepare($this->strategy);
        $this->assertSame(1, AgentActivity::where('campaign_id', $this->campaign->id)->where('action', 'conversion_goals_need_review')->count());
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Awareness']]);
        $this->assertSame('conversion_goal_intent_missing', $this->service->prepare($this->strategy)['issues'][0]->code);
        $this->assertSame([], $this->service->operations);
    }

    public function test_wrong_origin_and_foreign_custom_goal_are_never_overwritten(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Purchase', 'origin' => 'APP']]);
        $this->assertFalse($this->service->prepare($this->strategy)['ready']);
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Purchase']]);
        $this->service->configuration['customConversionGoal'] = 'customers/123/customConversionGoals/555';
        $state = $this->service->reconcile($this->strategy);
        $this->assertSame('conversion_goal_custom', $state['issues'][0]->code);
        $this->assertFalse($state['repairable']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_explicit_secondary_action_uses_campaign_custom_goal_without_changing_primaries(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Sign-up', 'conversion_action_id' => 8]]);
        $actions = $this->service->actions;
        $this->assertSame('custom', $this->service->prepare($this->strategy)['intent']['mode']);
        $this->assertTrue($this->service->inspect($this->strategy)['repairable']);
        $state = $this->service->reconcile($this->strategy);
        $this->assertTrue($state['ready']);
        $this->assertSame('SIGNUP', $state['intent']['category']);
        $this->assertSame('customers/123/customConversionGoals/99', $state['intent']['custom_goal']);
        $this->assertSame([false, false, false], array_column($this->service->goals, 'biddable'));
        $this->assertSame($actions, $this->service->actions);
        $this->assertSame(['customers/123/conversionActions/8'], $this->service->customs[0]['conversionActions']);
        $this->assertSame('Spectra campaign 9 strategy '.$this->strategy->id.' action 8', $this->service->customs[0]['name']);
        $this->assertTrue($this->service->prepare($this->strategy)['ready']);
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame(1, $this->service->created);
        $this->assertCount(1, $this->service->operations);
        $this->assertSame('active', $this->campaign->fresh()->status->value);
    }

    public function test_explicit_action_is_required_to_choose_between_secondary_actions(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Sign-up']]);
        $this->assertFalse($this->service->prepare($this->strategy)['ready']);
        $this->assertSame(0, $this->service->created);
    }

    public function test_own_site_signup_uses_authoritative_import_and_validate_only_not_stale_webpage(): void
    {
        $this->bindOwnSiteImport('signup_import', '8');
        $this->customer->update(['conversion_action_id' => '6']);
        $this->service->actions[] = ['resourceName' => 'customers/123/conversionActions/6', 'status' => 'ENABLED',
            'type' => 'WEBPAGE', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'primaryForGoal' => false];
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Sign-up']]);
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'validation'])]);
        $state = $this->service->reconcile($this->strategy);
        $this->assertTrue($state['ready']);
        $this->assertSame('customers/123/conversionActions/8', $state['intent']['action_resource']);
        Http::assertSent(fn ($request) => $request['validateOnly'] === true
            && $request['destinations'][0]['productDestinationId'] === '8'
            && $request['events'][0]['transactionId'] === 'validate-campaign-conversion-goal');
        Http::assertSentCount(1);
        $this->assertFalse($this->service->actions[1]['primaryForGoal']);
    }

    public function test_upload_clicks_type_and_website_origin_are_valid_for_paid_subscription(): void
    {
        $this->bindOwnSiteImport('paid_subscription', '7');
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['requestId' => 'validation'])]);
        $this->assertTrue($this->service->prepare($this->strategy)['ready']);
        $this->assertSame(0, $this->service->created);
        $this->assertSame([], $this->service->operations);
    }

    public function test_import_credentials_failure_blocks_all_bidding_mutations_and_remains_visible(): void
    {
        $this->bindOwnSiteImport('signup_import', '8');
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Sign-up']]);
        Http::fake(['datamanager.googleapis.com/*' => Http::response(['error' => ['message' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']], 403)]);
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame('conversion_goal_import_credentials', $state['issues'][0]->code);
        $this->assertSame('needs_review', $this->strategy->fresh()->execution_result['metadata']['conversion_goal_readiness']['status']);
        $this->assertSame(0, $this->service->created);
        $this->assertSame([], $this->service->operations);
        Http::assertSent(fn ($request) => $request['validateOnly'] === true);
    }

    public function test_missing_own_site_mapping_does_not_select_an_unrelated_primary(): void
    {
        config(['conversions.google_ads_customer_id' => '123']);
        Setting::set('conversion_resource_name.signup_import', null);
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Sign-up']]);
        $this->assertSame('conversion_goal_mapping_missing', $this->service->prepare($this->strategy)['issues'][0]->code);
        $this->assertSame([], $this->service->operations);
    }

    public function test_failed_read_persists_unknown_retains_last_ready_and_rethrows(): void
    {
        $this->service->reconcile($this->strategy);
        $last = $this->strategy->fresh()->execution_result['metadata']['conversion_goal_readiness']['last_ready'];
        $this->service->failRead = true;
        try {
            $this->service->inspect($this->strategy);
            $this->fail('A failed Google read must propagate to its reporting caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google read unavailable', $e->getMessage());
        }
        $state = $this->strategy->fresh()->execution_result['metadata']['conversion_goal_readiness'];
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertSame($last, $state['last_ready']);
    }

    public function test_unconfirmed_mutation_is_not_reported_as_a_repaired_campaign(): void
    {
        $this->service->reflectWrites = false;
        $state = $this->service->reconcile($this->strategy);
        $this->assertSame('conversion_goal_unverified', $state['issues'][0]->code);
        $this->assertFalse($state['ready']);
        $this->assertSame([], $state['actions']);
        $this->assertDatabaseMissing('agent_activities', ['campaign_id' => $this->campaign->id, 'action' => 'conversion_goals_updated']);
    }

    public function test_failed_custom_link_remembers_ownership_and_retry_reuses_the_created_goal(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $this->service->failWrite = true;
        try {
            $this->service->reconcile($this->strategy);
            $this->fail('Failed writes must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google write unavailable', $e->getMessage());
        }
        $this->assertSame('customers/123/customConversionGoals/99', $this->strategy->fresh()->execution_result['metadata']['conversion_goal_readiness']['managed_custom_goal']);
        $this->service->failWrite = false;
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame(1, $this->service->created);
    }

    public function test_paused_campaign_can_be_diagnosed_but_only_explicit_deployment_can_configure_it(): void
    {
        $this->campaign->update(['status' => 'paused', 'platform_status' => 'PAUSED']);
        $this->assertTrue($this->service->inspect($this->strategy)['repairable']);
        $this->assertFalse($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame([], $this->service->operations);
        $this->assertTrue($this->service->reconcile($this->strategy, allowInactive: true)['ready']);
        $this->assertSame('paused', $this->campaign->fresh()->status->value);
        $this->assertSame('PAUSED', $this->campaign->fresh()->platform_status);
    }

    public function test_billing_pause_blocks_managed_campaign_mutations_but_self_funded_accounts_are_exempt(): void
    {
        Setting::set('managed_billing_enabled', true, 'boolean');
        $credit = AdSpendCredit::create(['customer_id' => $this->customer->id, 'current_balance' => 100,
            'payment_status' => AdSpendCredit::PAYMENT_PAUSED, 'status' => AdSpendCredit::STATUS_ACTIVE]);
        $this->assertFalse($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame([], $this->service->operations);
        $this->customer->update(['google_ads_link_status' => 'active']);
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertEquals(100, $credit->fresh()->current_balance);
        $this->assertSame(AdSpendCredit::PAYMENT_PAUSED, $credit->fresh()->payment_status);
    }

    public function test_live_google_pause_blocks_reconciliation_even_when_local_status_is_stale(): void
    {
        $this->service->remoteStatus = 'PAUSED';
        $this->assertTrue($this->service->inspect($this->strategy)['repairable']);
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame('conversion_goal_campaign_inactive', $state['issues'][0]->code);
        $this->assertSame([], $this->service->operations);
        $this->assertSame(0, $this->service->created);
        $this->assertTrue($this->service->reconcile($this->strategy, allowInactive: true)['ready']);
        $this->assertSame('PAUSED', $this->service->remoteStatus);
    }

    public function test_missing_remote_status_blocks_mutations_as_unknown(): void
    {
        $this->service->remoteStatus = null;
        $state = $this->service->reconcile($this->strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertSame([], $this->service->operations);
    }

    public function test_existing_exact_managed_custom_goal_can_be_adopted_without_duplicate_creation(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $this->service->customs = [['resourceName' => 'customers/123/customConversionGoals/99',
            'name' => 'Spectra campaign 9 strategy '.$this->strategy->id.' action 8', 'status' => 'ENABLED',
            'conversionActions' => ['customers/123/conversionActions/8']]];
        $this->service->configuration['customConversionGoal'] = 'customers/123/customConversionGoals/99';
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame(0, $this->service->created);
        $this->assertSame('customers/123/customConversionGoals/99', $this->strategy->fresh()->execution_result['metadata']['conversion_goal_readiness']['managed_custom_goal']);
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertCount(1, $this->service->operations);
    }

    public function test_unrelated_custom_goal_is_not_adopted_for_a_selected_secondary_action(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $this->service->configuration['customConversionGoal'] = 'customers/123/customConversionGoals/77';
        $this->service->customs = [['resourceName' => 'customers/123/customConversionGoals/77', 'name' => 'Manually selected goal',
            'status' => 'ENABLED', 'conversionActions' => ['customers/123/conversionActions/8']]];
        $this->assertSame('conversion_goal_custom', $this->service->reconcile($this->strategy)['issues'][0]->code);
        $this->assertSame(0, $this->service->created);
        $this->assertSame([], $this->service->operations);
    }

    public function test_foreign_campaign_resource_never_produces_operations(): void
    {
        try {
            $this->service->reconcile($this->strategy, 'customers/999/campaigns/9');
            $this->fail('Cross-account campaign resources must be rejected.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }
        $this->assertSame([], $this->service->operations);
    }

    public function test_new_unverified_strategy_is_diagnosed_and_repaired_without_spend_age_or_saved_action_id_gates(): void
    {
        $this->campaign->update(['google_ads_campaign_id' => null]);
        $this->customer->update(['conversion_action_id' => 'stale']);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $this->service);
        $findings = app(CampaignDiagnosticsAgent::class)->diagnose($this->campaign->fresh());
        $finding = collect($findings)->firstWhere('type', 'conversion_goal_mismatch');
        $this->assertNotNull($finding);
        $this->assertTrue($finding['can_auto_fix']);
        Feature::for($this->customer)->activate(AutoHealing::class);
        $result = (new CampaignRemediationAgent($this->createMock(GeminiService::class)))->remediate($this->campaign->fresh(), [$finding]);
        $this->assertSame([], $result['errors']);
        $this->assertSame('campaign_conversion_goals_verified', $result['actions_taken'][0]['type']);
        $this->assertTrue($this->service->inspect($this->strategy)['ready']);
    }

    public function test_custom_goal_verifier_accepts_selected_secondary_and_rejects_wrong_origin_or_extra_goal(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $state = $this->service->reconcile($this->strategy);
        $actual = ['conversion_goal_config' => ['conversionGoalCampaignConfig' => $this->service->configuration],
            'conversion_actions' => array_map(fn ($action) => ['conversionAction' => $action], $this->service->actions),
            'custom_conversion_goals' => array_map(fn ($custom) => ['customConversionGoal' => $custom], $this->service->customs),
            'goals' => array_map(fn ($goal) => ['campaignConversionGoal' => $goal], $this->service->goals)];
        $check = app(GoogleSearchConfigurationCheck::class);
        $this->assertSame([], $check->conversionGoalIssues($state['intent'], $actual));
        $actual['conversion_actions'][1]['conversionAction']['origin'] = 'APP';
        $this->assertNotEmpty($check->conversionGoalIssues($state['intent'], $actual));
        $actual['conversion_actions'][1]['conversionAction']['origin'] = 'WEBSITE';
        $actual['goals'][0]['campaignConversionGoal']['biddable'] = true;
        $this->assertNotEmpty($check->conversionGoalIssues($state['intent'], $actual));
    }

    public function test_readiness_write_locks_the_latest_row_and_preserves_other_workers_metadata(): void
    {
        $stale = $this->strategy->fresh();
        $latest = $this->strategy->execution_result;
        $otherReadiness = ['status' => 'needs_review', 'ad_strength' => ['unresolved' => [['ad' => 'rsa-1', 'reason' => 'under_review']]],
            'checked_at' => now()->toIso8601String()];
        $latest['metadata']['google_readiness'] = $otherReadiness;
        $latest['metadata']['another_agent'] = ['job_id' => 'other-worker'];
        $latest['metadata']['google_search_baseline']['ads'] = [['resource' => 'reviewed-rsa']];
        $latest['warnings'] = [['code' => 'under_review', 'message' => 'Awaiting policy review']];
        $this->strategy->update(['execution_result' => $latest]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $returned = $this->service->prepare($stale);

        $saved = $this->strategy->fresh()->execution_result;
        $this->assertSame($otherReadiness, $saved['metadata']['google_readiness']);
        $this->assertSame($latest['metadata']['another_agent'], $saved['metadata']['another_agent']);
        $this->assertSame($latest['metadata']['google_search_baseline']['ads'], $saved['metadata']['google_search_baseline']['ads']);
        $this->assertSame($latest['warnings'], $saved['warnings']);
        $this->assertSame('ready', $saved['metadata']['conversion_goal_readiness']['status']);
        $this->assertSame($saved['metadata']['conversion_goal_readiness']['last_ready'], $returned['last_ready']);
        $this->assertSame($saved['metadata']['conversion_goal_readiness']['checked_at'], $returned['checked_at']);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, '"strategies"') && str_contains($sql, 'for update')));
        $this->assertSame($saved, $stale->execution_result);
        $this->assertFalse($stale->isDirty('execution_result'));
    }

    public function test_failed_check_preserves_other_readiness_and_custom_ownership_from_latest_row(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $this->service->reconcile($this->strategy);
        $stale = $this->strategy->fresh();
        $latest = $stale->execution_result;
        $latest['metadata']['google_readiness'] = ['status' => 'unknown', 'ad_strength' => ['errors' => [['code' => 'read_timeout']]]];
        $this->strategy->update(['execution_result' => $latest]);
        $this->service->failRead = true;
        try {
            $this->service->inspect($stale);
            $this->fail('A failed Google read must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google read unavailable', $e->getMessage());
        }
        $saved = $this->strategy->fresh()->execution_result;
        $this->assertSame($latest['metadata']['google_readiness'], $saved['metadata']['google_readiness']);
        $this->assertSame('unknown', $saved['metadata']['conversion_goal_readiness']['status']);
        $this->assertSame('customers/123/customConversionGoals/99', $saved['metadata']['conversion_goal_readiness']['managed_custom_goal']);
        $this->assertSame($latest['metadata']['conversion_goal_readiness']['last_ready'], $saved['metadata']['conversion_goal_readiness']['last_ready']);
        $this->assertSame($latest['metadata']['google_search_baseline'], $saved['metadata']['google_search_baseline']);
    }

    public function test_inactive_strategy_cannot_write_readiness_for_another_customer(): void
    {
        $other = Customer::factory()->create(['google_ads_customer_id' => '999']);
        $campaign = Campaign::factory()->create(['customer_id' => $other->id, 'status' => 'paused']);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Search',
            'execution_result' => ['metadata' => ['google_readiness' => ['status' => 'ready']]]]);
        $before = $strategy->execution_result;
        try {
            $this->service->reconcile($strategy);
            $this->fail('An inactivity result must not bypass customer ownership.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('service customer', $e->getMessage());
        }
        $this->assertSame($before, $strategy->fresh()->execution_result);
        $this->assertSame([], $this->service->operations);
    }

    public function test_manual_pause_during_goal_reads_is_rechecked_before_category_write(): void
    {
        $this->service->duringRead = function ($query): void {
            if (str_contains($query, 'FROM campaign_conversion_goal ')) {
                $this->service->duringRead = null;
                Campaign::whereKey($this->campaign->id)->update(['status' => 'paused']);
            }
        };
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('campaign not active', $state['issues'][0]->message);
        $this->assertSame([], $this->service->operations);
        $this->assertSame('paused', $this->campaign->fresh()->status->value);
    }

    public function test_external_automation_flag_change_during_reads_blocks_custom_creation_even_when_cached_true(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        Feature::for($this->customer)->activate(AutoHealing::class);
        $this->assertTrue(Feature::for($this->customer)->active(AutoHealing::class));
        $this->service->duringRead = function ($query): void {
            if (str_contains($query, 'FROM custom_conversion_goal ')) {
                $this->service->duringRead = null;
                DB::table('features')->where('name', AutoHealing::class)->update(['value' => 'false']);
            }
        };
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('auto healing disabled', $state['issues'][0]->message);
        $this->assertSame(0, $this->service->created);
        $this->assertSame([], $this->service->operations);
    }

    public function test_remote_pause_after_custom_creation_blocks_linking_and_keeps_ownership_for_retry(): void
    {
        $this->strategy->update(['conversion_goals' => ['primary_goal' => 'Signup', 'conversion_action_id' => '8']]);
        $this->service->afterCustomCreated = function (): void {
            $this->service->remoteStatus = 'PAUSED';
        };
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame(1, $this->service->created);
        $this->assertSame([], $this->service->operations);
        $this->assertSame([], $state['actions']);
        $this->assertSame('customers/123/customConversionGoals/99', $state['managed_custom_goal']);
        $this->assertArrayNotHasKey('customConversionGoal', $this->service->configuration);
        $this->service->afterCustomCreated = null;
        $this->service->remoteStatus = 'ENABLED';
        $this->assertTrue($this->service->reconcile($this->strategy)['ready']);
        $this->assertSame(1, $this->service->created);
    }

    public function test_payment_hold_during_reads_is_rechecked_without_spending_or_changing_ledger(): void
    {
        Setting::set('managed_billing_enabled', true, 'boolean');
        $credit = AdSpendCredit::create(['customer_id' => $this->customer->id, 'current_balance' => 100,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT, 'status' => AdSpendCredit::STATUS_ACTIVE]);
        $this->service->duringRead = function ($query) use ($credit): void {
            if (str_contains($query, 'FROM campaign_conversion_goal ')) {
                $this->service->duringRead = null;
                $credit->update(['payment_status' => AdSpendCredit::PAYMENT_PAUSED]);
            }
        };
        $state = $this->service->reconcile($this->strategy);
        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('ad spend unfunded', $state['issues'][0]->message);
        $this->assertSame([], $this->service->operations);
        $this->assertEquals(100, $credit->fresh()->current_balance);
        $this->assertSame(AdSpendCredit::PAYMENT_PAUSED, $credit->fresh()->payment_status);
    }

    public function test_approval_withdrawal_or_end_date_during_reads_blocks_category_updates(): void
    {
        foreach (['approval', 'end_date'] as $hold) {
            $this->strategy->refresh()->update(['signed_off_at' => now()]);
            $this->campaign->refresh()->update(['end_date' => now()->addDays(30)]);
            $this->service->duringRead = function ($query) use ($hold): void {
                if (str_contains($query, 'FROM campaign_conversion_goal ')) {
                    $this->service->duringRead = null;
                    if ($hold === 'approval') {
                        Strategy::whereKey($this->strategy->id)->update(['signed_off_at' => null]);
                    } else {
                        Campaign::whereKey($this->campaign->id)->update(['end_date' => now()->subDay()]);
                    }
                }
            };
            $state = $this->service->reconcile($this->strategy);
            $this->assertFalse($state['ready']);
            $this->assertStringContainsString($hold === 'approval' ? 'strategy not approved' : 'campaign ended', $state['issues'][0]->message);
            $this->assertSame([], $this->service->operations);
        }
    }

    private function bindOwnSiteImport(string $event, string $id): void
    {
        config(['conversions.google_ads_customer_id' => '123']);
        Setting::set('conversion_resource_name.'.$event, 'customers/123/conversionActions/'.$id);
        $dataManager = new DataManagerService(new MccAccount(['google_customer_id' => '987']));
        (new \ReflectionProperty($dataManager, 'cachedToken'))->setValue($dataManager, 'fixture-token');
        $this->app->instance(DataManagerService::class, $dataManager);
    }
}

/** In-memory Google transport; SDK operations still exercise the real field masks and resource scope. */
class CampaignGoalsFixture extends ReconcileCampaignConversionGoals
{
    public array $actions = [
        ['resourceName' => 'customers/123/conversionActions/7', 'status' => 'ENABLED', 'type' => 'UPLOAD_CLICKS', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'primaryForGoal' => true],
        ['resourceName' => 'customers/123/conversionActions/8', 'status' => 'ENABLED', 'type' => 'UPLOAD_CLICKS', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'primaryForGoal' => false],
        ['resourceName' => 'customers/123/conversionActions/9', 'status' => 'ENABLED', 'type' => 'WEBPAGE', 'category' => 'SUBMIT_LEAD_FORM', 'origin' => 'WEBSITE', 'primaryForGoal' => false],
    ];

    public array $goals = [
        ['resourceName' => 'customers/123/campaignConversionGoals/9~PURCHASE~WEBSITE', 'category' => 'PURCHASE', 'origin' => 'WEBSITE', 'biddable' => false],
        ['resourceName' => 'customers/123/campaignConversionGoals/9~SIGNUP~WEBSITE', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'biddable' => true],
        ['resourceName' => 'customers/123/campaignConversionGoals/9~SUBMIT_LEAD_FORM~WEBSITE', 'category' => 'SUBMIT_LEAD_FORM', 'origin' => 'WEBSITE', 'biddable' => true],
    ];

    public array $configuration = ['resourceName' => 'customers/123/conversionGoalCampaignConfigs/9', 'goalConfigLevel' => 'CUSTOMER'];

    public array $customs = [];

    public array $operations = [];

    public int $created = 0;

    public bool $failRead = false;

    public bool $failWrite = false;

    public bool $reflectWrites = true;

    public ?string $remoteStatus = 'ENABLED';

    public ?\Closure $duringRead = null;

    public ?\Closure $afterCustomCreated = null;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    protected function readRows(string $customerId, string $query): array
    {
        if ($this->duringRead) {
            ($this->duringRead)($query);
        }
        if ($this->failRead) {
            throw new \RuntimeException('Google read unavailable');
        }
        if (str_contains($query, 'FROM conversion_action ')) {
            return array_map(fn ($action) => ['conversionAction' => $action], $this->actions);
        }
        if (str_contains($query, 'FROM campaign ')) {
            return $this->remoteStatus ? [['campaign' => ['status' => $this->remoteStatus]]] : [];
        }
        if (str_contains($query, 'FROM conversion_goal_campaign_config ')) {
            return $this->configuration ? [['conversionGoalCampaignConfig' => $this->configuration]] : [];
        }
        if (str_contains($query, 'FROM campaign_conversion_goal ')) {
            return array_map(fn ($goal) => ['campaignConversionGoal' => $goal], $this->goals);
        }
        if (str_contains($query, 'FROM custom_conversion_goal ')) {
            $customs = $this->customs;
            foreach (['name', 'resource_name'] as $field) {
                if (preg_match("/custom_conversion_goal\\.{$field} = '([^']+)'/", $query, $match)) {
                    $key = $field === 'name' ? 'name' : 'resourceName';
                    $customs = array_values(array_filter($customs, fn ($custom) => $custom[$key] === $match[1]));
                }
            }

            return array_map(fn ($custom) => ['customConversionGoal' => $custom], $customs);
        }
        throw new \LogicException('Unexpected query: '.$query);
    }

    protected function createCustomGoal(string $customerId, string $name, string $action): ?string
    {
        $this->created++;
        $this->customs[] = ['resourceName' => 'customers/123/customConversionGoals/99', 'name' => $name,
            'status' => 'ENABLED', 'conversionActions' => [$action]];
        if ($this->afterCustomCreated) {
            ($this->afterCustomCreated)();
        }

        return 'customers/123/customConversionGoals/99';
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        if ($this->failWrite) {
            throw new \RuntimeException('Google write unavailable');
        }
        $this->operations[] = $operations;
        if (! $this->reflectWrites || $this->dryRun) {
            return;
        }
        foreach ($operations as $operation) {
            if ($operation->hasCampaignConversionGoalOperation()) {
                $update = $operation->getCampaignConversionGoalOperation()->getUpdate();
                foreach ($this->goals as &$goal) {
                    if ($goal['resourceName'] === $update->getResourceName()) {
                        $goal['biddable'] = $update->getBiddable();
                    }
                }
                unset($goal);
            } elseif ($operation->hasConversionGoalCampaignConfigOperation()) {
                $this->configuration['customConversionGoal'] = $operation->getConversionGoalCampaignConfigOperation()->getUpdate()->getCustomConversionGoal();
                $this->configuration['goalConfigLevel'] = 'CAMPAIGN';
            } else {
                throw new \LogicException('Only campaign goal operations are permitted.');
            }
        }
    }
}
