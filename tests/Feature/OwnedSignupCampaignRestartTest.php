<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Campaigns\RestartOwnedSignupCampaign;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\SiteSignupConversionService;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Google\Ads\GoogleAds\V26\Enums\CustomerStatusEnum\CustomerStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OwnedSignupCampaignRestartTest extends TestCase
{
    use DatabaseTransactions;

    private Campaign $campaign;

    private User $admin;

    private SpectraConversionEvent $receipt;

    private array $snapshot;

    private array $goal;

    private array $destination;

    private int $enables = 0;

    private bool $confirm = true;

    private bool $reject = false;

    private string $resource = 'customers/1234567890/campaigns/8844';

    private string $action = 'customers/1234567890/conversionActions/77';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-22 01:00:00', 'UTC'));
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $customer = Customer::factory()->create(['website' => 'https://sitetospend.com', 'google_ads_customer_id' => '1234567890', 'is_sandbox' => false,
            'service_type' => 'managed', 'currency_code' => 'AUD', 'timezone' => 'Australia/Sydney']);
        config(['conversions.advertiser_customer_uuid' => $customer->uuid, 'conversions.google_ads_customer_id' => '1234567890']);
        Setting::set('conversion_resource_name.signup_import', $this->action);
        AdSpendCredit::create(['customer_id' => $customer->id, 'initial_credit_amount' => 500, 'current_balance' => 500, 'currency' => 'AUD',
            'status' => 'active', 'payment_status' => 'current']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => $this->resource,
            'status' => 'paused', 'platform_status' => 'PAUSED', 'approved_daily_budget' => 60, 'total_budget' => 420,
            'budget_confirmed_at' => now()->subWeek(), 'start_date' => '2026-11-17', 'end_date' => '2026-11-22',
            'spend_safety_hold' => ['reason' => RestartOwnedSignupCampaign::HOLD, 'verified_at' => now()->subHours(5)->toIso8601String()]]);
        Strategy::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => 'Google Ads', 'campaign_type' => 'performance_max',
            'google_ads_campaign_id' => $this->resource, 'signed_off_at' => now()->subWeek(), 'daily_budget' => 60]);
        $user = User::factory()->create(['tenant_key' => 'sitetospend', 'ad_measurement_consent' => true, 'email_verified_at' => now()]);
        $this->receipt = app(SiteSignupConversionService::class)->record($user, 'signup-email-v1', formSuppliedEmail: true);
        $this->receipt->update(['google_request_id' => 'actual-request', 'google_accepted_at' => now(), 'google_processing_checked_at' => now(),
            'uploaded_to_google' => true, 'google_processing_status' => 'SUCCESS', 'google_processing_details' => ['warnings' => []]]);
        $this->snapshot = ['resource' => $this->resource, 'customer_id' => '1234567890', 'customer_status' => CustomerStatus::ENABLED,
            'manager_or_test' => false, 'status' => GoogleStatus::PAUSED, 'campaign_type' => AdvertisingChannelType::PERFORMANCE_MAX,
            'account_timezone' => 'Australia/Sydney', 'currency_code' => 'AUD', 'daily_budget_micros' => 60_000_000,
            'campaign_start_date' => '2026-11-17', 'campaign_end_date' => '2026-11-22', 'cost_micros' => 126_000_000,
            'starts_at_local' => '2026-11-17 11:12:51', 'ends_at_local' => '2026-11-22 23:59:59'];
        $this->goal = ['ready' => true, 'configuration_verified' => true, 'campaign_resource' => $this->resource,
            'intent' => ['mode' => 'custom', 'category' => 'SIGNUP', 'custom_goal' => 'custom', 'action_resource' => $this->action]];
        $this->destination = ['destination' => ['operatingAccount' => ['accountId' => '1234567890', 'accountType' => 'GOOGLE_ADS'], 'productDestinationId' => '77'],
            'requestStatus' => 'SUCCESS', 'eventsIngestionStatus' => ['recordCount' => '1']];
        $spend = $this->createMock(GetCampaignSpendSafety::class);
        $spend->method('forCampaign')->willReturnCallback(fn () => $this->snapshot);
        $this->app->bind(GetCampaignSpendSafety::class, fn () => $spend);
        $goal = $this->createMock(ReconcileCampaignConversionGoals::class);
        $goal->method('inspect')->willReturnCallback(fn () => $this->goal);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goal);
        $status = $this->createMock(UpdateCampaignStatus::class);
        $status->method('enable')->willReturnCallback(function () {
            $this->enables++;
            if ($this->reject) {
                return ['success' => false, 'rejected' => true];
            }
            $this->snapshot['status'] = GoogleStatus::ENABLED;

            return ['success' => true];
        });
        $status->method('confirmedStatus')->willReturnCallback(fn ($account, $resource, $state) => $this->confirm && $this->snapshot['status'] === GoogleStatus::value($state));
        $this->app->bind(UpdateCampaignStatus::class, fn () => $status);
        $dataManager = $this->createMock(DataManagerService::class);
        $dataManager->method('retrieveRequestStatus')->willReturnCallback(fn () => ['success' => true, 'destinations' => [$this->destination]]);
        $this->app->instance(DataManagerService::class, $dataManager);
    }

    public function test_explicit_retry_after_a_definite_rejection_requires_a_confirmed_pause(): void
    {
        $this->reject = true;
        try {
            app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
            $this->fail('A rejected enable cannot clear a spending hold.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Google rejected', $e->getMessage());
        }
        $this->assertSame('rejected', $this->campaign->fresh()->spend_guardrails['pmax_signup_restart']['status']);
        $this->reject = false;
        app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
        $this->assertSame(CampaignStatus::Active, $this->campaign->fresh()->status);
        $this->assertSame(2, $this->enables);
    }

    public function test_operator_restart_is_audited_idempotent_and_reconciles_interrupted_readback(): void
    {
        $this->confirm = false;
        try {
            app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
            $this->fail('Missing readback must leave the hold in place.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not confirm', $e->getMessage());
        }
        $this->assertSame(RestartOwnedSignupCampaign::HOLD, $this->campaign->fresh()->spend_safety_hold['reason']);
        $this->assertSame('requested', $this->campaign->fresh()->spend_guardrails['pmax_signup_restart']['status']);
        $this->confirm = true;
        app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
        app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
        $this->assertSame(1, $this->enables);
        $current = $this->campaign->fresh();
        $this->assertSame(CampaignStatus::Active, $current->status);
        $this->assertNull($current->spend_safety_hold);
        $this->assertSame('2026-11-22', RestartOwnedSignupCampaign::verifiedBaselineDate($current, $this->resource, 'Australia/Sydney'));
        $this->assertNull(RestartOwnedSignupCampaign::verifiedBaselineDate($current, 'other', 'Australia/Sydney'));
        $this->assertSame(1, AgentActivity::where('campaign_id', $current->id)->where('action', 'signup_repair_restart_verified')->count());
    }

    #[DataProvider('blockers')]
    public function test_other_holds_unverified_registrations_and_changed_spend_never_enable(string $blocker): void
    {
        match ($blocker) {
            'wallet' => $this->campaign->customer->adSpendCredit()->update(['campaigns_paused_at' => now()]),
            'insufficient funds' => $this->campaign->customer->adSpendCredit()->update(['current_balance' => 0.01]),
            'other hold' => $this->campaign->update(['spend_safety_hold' => ['reason' => 'manual_pause', 'verified_at' => now()]]),
            'old receipt' => $this->campaign->update(['spend_safety_hold' => ['reason' => RestartOwnedSignupCampaign::HOLD, 'verified_at' => now()->toIso8601String()]]),
            'fabricated registration' => $this->receipt->update(['deduplication_key' => 'manual']),
            'wrong tenant' => $this->receipt->user->update(['tenant_key' => 'another']),
            'wrong action' => $this->goal['intent']['action_resource'] = 'customers/1234567890/conversionActions/888',
            'failed processing' => $this->destination['requestStatus'] = 'PARTIAL_SUCCESS',
            'other account' => $this->snapshot['customer_id'] = '9999999999',
            'other customer' => config(['conversions.advertiser_customer_uuid' => 'elsewhere']),
            'search' => $this->snapshot['campaign_type'] = AdvertisingChannelType::SEARCH,
            'changed budget' => $this->snapshot['daily_budget_micros'] = 60_000_001,
            'expired' => $this->campaign->update(['end_date' => '2026-11-21']),
            'future' => $this->campaign->update(['start_date' => '2026-11-23']),
            'unapproved extension' => $this->snapshot['campaign_end_date'] = '2026-11-23',
            'non-admin' => $this->admin->roles()->detach(),
            default => $this->fail('Unknown case '.$blocker),
        };
        try {
            app(RestartOwnedSignupCampaign::class)->start($this->campaign, $this->admin);
            $this->fail('Unexpected restart for '.$blocker);
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(0, $this->enables);
        $this->assertSame(CampaignStatus::Paused, $this->campaign->fresh()->status);
    }

    public static function blockers(): array
    {
        return array_map(fn ($case) => [$case], ['wallet', 'insufficient funds', 'other hold', 'old receipt', 'fabricated registration', 'wrong tenant', 'wrong action',
            'failed processing', 'other account', 'other customer', 'search', 'changed budget', 'expired', 'future', 'unapproved extension', 'non-admin']);
    }
}
