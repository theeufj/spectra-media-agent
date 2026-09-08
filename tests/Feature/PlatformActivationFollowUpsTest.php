<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\CampaignStatusHelper;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The cross-platform half of the deploy story: Microsoft and LinkedIn campaigns
 * could be created but never switched on, never verified, and — when Microsoft
 * rejected one — never told why.
 *
 * `campaigns:activate` handled google and facebook and `continue`d otherwise,
 * and was not scheduled at all, so even a Google campaign that legitimately
 * deployed paused stayed paused until somebody ran the command by hand.
 */
class PlatformActivationFollowUpsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['campaigns.testing_mode_default' => false, 'campaigns.default_status' => 'ENABLED']);
        Setting::where('key', 'campaign_testing_mode')->delete();

        // EnabledPlatform memoises its slug map for five minutes and the flush
        // hook only fires on write, so a row rolled back by DatabaseTransactions
        // would otherwise keep answering for the rest of the process.
        Cache::forget('enabled_platform_slugs');
    }

    private function disablePlatform(string $slug): void
    {
        // A migration already seeds microsoft and linkedin rows, so this flips
        // the existing switch rather than inserting a duplicate slug.
        EnabledPlatform::updateOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'is_enabled' => false],
        );
    }

    /**
     * A campaign whose strategies are deployed and settled past the verifier's
     * window — the shape the scheduled sweep is meant to pick up.
     */
    private function deployedStrategy(string $platform, array $customerAttributes = [], array $campaignAttributes = []): Strategy
    {
        $customer = Customer::factory()->create($customerAttributes);
        $campaign = Campaign::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::Active,
        ], $campaignAttributes));

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => $platform,
            'deployment_status' => 'deployed',
        ]);

        // updated_at is not fillable, so an update() would silently drop it.
        \DB::table('strategies')->where('id', $strategy->id)->update(['updated_at' => now()->subHour()]);

        return $strategy->refresh();
    }

    // ---------------------------------------------------------------- helper

    public function test_microsoft_and_linkedin_get_their_own_status_vocabulary(): void
    {
        $this->assertSame('Active', CampaignStatusHelper::getMicrosoftAdsStatus());
        $this->assertSame('Paused', CampaignStatusHelper::getMicrosoftAdsStatus('PAUSED'));
        $this->assertSame('ACTIVE', CampaignStatusHelper::getLinkedInAdsStatus());
        $this->assertSame('PAUSED', CampaignStatusHelper::getLinkedInAdsStatus('PAUSED'));
    }

    public function test_testing_mode_still_wins_for_microsoft_and_linkedin(): void
    {
        Setting::create(['key' => 'campaign_testing_mode', 'value' => '1']);

        // The whole point of the shared helper: one switch, four platforms. An
        // explicitly requested ACTIVE must not escape testing mode.
        $this->assertSame('Paused', CampaignStatusHelper::getMicrosoftAdsStatus('ACTIVE'));
        $this->assertSame('PAUSED', CampaignStatusHelper::getLinkedInAdsStatus('ACTIVE'));
    }

    // ------------------------------------------------------------ activation

    public function test_microsoft_and_linkedin_are_no_longer_skipped_as_unknown_platforms(): void
    {
        // Kill switches off so the services short-circuit before authenticating:
        // the branch under test is "is this platform dispatched at all", not the
        // API call it makes.
        $this->disablePlatform('microsoft');
        $this->disablePlatform('linkedin');

        foreach ([
            ['Microsoft Ads', ['microsoft_ads_account_id' => '222'], ['microsoft_ads_campaign_id' => '900']],
            ['LinkedIn Ads', ['linkedin_ads_account_id' => '333'], ['linkedin_campaign_id' => '901']],
        ] as [$platform, $customerAttributes, $campaignAttributes]) {
            $strategy = $this->deployedStrategy($platform, $customerAttributes, $campaignAttributes);

            Artisan::call('campaigns:activate', ['campaign' => $strategy->campaign_id]);
            $output = Artisan::output();

            $this->assertStringNotContainsString('Unknown platform', $output, "{$platform} is still unroutable");
            $this->assertStringContainsString('Activation failed', $output);
        }
    }

    public function test_a_campaign_spectra_stood_down_is_not_switched_back_on(): void
    {
        // Billing pause, policy violation, deactivated customer: all of them
        // leave the strategy at deployment_status 'deployed', so an unattended
        // sweep would resume spend nobody authorised.
        $strategy = $this->deployedStrategy(
            'Google Ads (SEM)',
            ['google_ads_customer_id' => '123-456-7890'],
            ['status' => CampaignStatus::Paused, 'google_ads_campaign_id' => 'customers/1234567890/campaigns/55'],
        );

        Artisan::call('campaigns:activate', ['campaign' => $strategy->campaign_id]);
        $output = Artisan::output();

        $this->assertStringContainsString('leaving it alone', $output);
        $this->assertSame('deployed', $strategy->fresh()->deployment_status);
    }

    public function test_a_freshly_deployed_strategy_is_left_for_the_verifier(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => CampaignStatus::Active,
        ]);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            'deployment_status' => 'deployed',
        ]);

        // VerifyDeployment only selects deployment_status = 'deployed'; taking a
        // strategy to 'active' the moment it lands would take it out from under
        // the verifier and the deployment would never be checked.
        Artisan::call('campaigns:activate');

        $this->assertStringNotContainsString("Strategy {$strategy->id} ", Artisan::output());
        $this->assertSame('deployed', $strategy->fresh()->deployment_status);
    }

    public function test_activation_is_scheduled(): void
    {
        // Read the source, as ScheduledFanOutJobsTest does: the built schedule
        // cannot tell an entry apart from any other CallbackEvent, and what
        // matters here is that the entry exists at all — the command shipped
        // unscheduled, which is why a paused deploy stayed paused.
        $this->assertStringContainsString(
            "Schedule::command('campaigns:activate')",
            file_get_contents(base_path('routes/console.php')),
        );
    }

    // ---------------------------------------------------------- verification

    public function test_the_verifier_covers_all_four_platforms(): void
    {
        $verifier = new DeploymentVerifier;

        $this->assertTrue($verifier->supports('Google Ads (SEM)'));
        $this->assertTrue($verifier->supports('Facebook Ads'));
        $this->assertTrue($verifier->supports('Microsoft Ads'));
        $this->assertTrue($verifier->supports('LinkedIn Ads'));

        $this->assertFalse($verifier->supports('TikTok Ads'));
        $this->assertFalse($verifier->supports(null));
    }

    public function test_a_platform_whose_kill_switch_is_off_is_not_verifiable(): void
    {
        $this->disablePlatform('microsoft');

        // The base service skips authentication entirely when the platform is
        // disabled, so every check would answer "absent" and mail the customer
        // a deployment failure for a campaign sitting on the platform.
        $this->assertFalse((new DeploymentVerifier)->supports('Microsoft Ads'));
    }

    public function test_the_verifier_reads_the_platform_id_key_the_agents_actually_write(): void
    {
        // ExecutionResult::addPlatformId() is called with 'campaign' everywhere;
        // 'campaign_id' — the key the verifier used to read — is written nowhere,
        // so the recorded ids were never consulted at all.
        $ids = new ReflectionMethod(DeploymentVerifier::class, 'platformCampaignId');
        $ids->setAccessible(true);

        $this->assertSame('999', $ids->invoke(new DeploymentVerifier, ['campaign' => '999']));
        $this->assertNull($ids->invoke(new DeploymentVerifier, []));
    }

    // -------------------------------------------------------- microsoft SOAP

    public function test_a_rejected_microsoft_campaign_hands_back_its_partial_errors(): void
    {
        $this->disablePlatform('microsoft');

        $service = new StubbedMicrosoftCampaignService(Customer::factory()->create([
            'microsoft_ads_account_id' => '222',
        ]));
        $service->response = ['PartialErrors' => ['BatchError' => [
            ['Index' => 0, 'Code' => 'CampaignServiceInvalidBudget', 'Message' => 'Daily budget is below the minimum'],
        ]]];

        // Returning null here threw the explanation away, so the execution agent
        // — which knows how to read PartialErrors — could only ever report
        // "empty response from AddCampaigns".
        $result = $service->createSearchCampaign(['name' => 'X', 'daily_budget' => 1]);

        $this->assertIsArray($result);
        $this->assertStringContainsString(
            'Daily budget is below the minimum',
            json_encode($result['PartialErrors'] ?? []),
        );
    }

    public function test_a_rejected_microsoft_status_write_is_not_reported_as_applied(): void
    {
        $this->disablePlatform('microsoft');

        $service = new StubbedMicrosoftCampaignService(Customer::factory()->create([
            'microsoft_ads_account_id' => '222',
        ]));

        $service->response = ['PartialErrors' => ['BatchError' => [
            ['Index' => 0, 'Code' => 'CampaignServiceEditorialError', 'Message' => 'nope'],
        ]]];
        $this->assertFalse($service->updateStatus('900', 'Active'));
        $this->assertFalse($service->updateBudget('900', 25.0));

        $service->response = ['PartialErrors' => null];
        $this->assertTrue($service->updateStatus('900', 'Active'));
    }

    // ------------------------------------------------------------- keywords

    public function test_brand_protection_negatives_are_not_added_as_exact(): void
    {
        $builder = new SearchKeywordBuilder(Customer::factory()->create());
        $matchType = new ReflectionMethod(SearchKeywordBuilder::class, 'negativeMatchType');
        $matchType->setAccessible(true);

        // 'free' at EXACT blocks only the literal query "free" while the
        // campaign's positives default to BROAD — the criterion is created and
        // counted, so the log and the Google UI both look right.
        $this->assertSame(KeywordMatchType::BROAD, $matchType->invoke($builder, 'free'));
        $this->assertSame(KeywordMatchType::BROAD, $matchType->invoke($builder, 'torrent'));

        // Multi-word intent phrases keep their word order; negative broad would
        // block anything containing both words separately.
        $this->assertSame(KeywordMatchType::PHRASE, $matchType->invoke($builder, 'how to'));

        $this->assertNotSame(KeywordMatchType::EXACT, $matchType->invoke($builder, 'free'));
    }

    // ------------------------------------------------------- auction insights

    public function test_auction_insights_queries_a_report_that_exists_in_v22(): void
    {
        $source = file_get_contents(app_path('Services/GoogleAds/CommonServices/GetAuctionInsights.php'));

        // There is no auction-insight resource and no GoogleAdsRow::getAuctionInsight():
        // the report is the campaign resource segmented by auction_insight_domain.
        $row = new \ReflectionClass(\Google\Ads\GoogleAds\V22\Services\GoogleAdsRow::class);
        $segments = new \ReflectionClass(\Google\Ads\GoogleAds\V22\Common\Segments::class);
        $metrics = new \ReflectionClass(\Google\Ads\GoogleAds\V22\Common\Metrics::class);

        $this->assertFalse($row->hasMethod('getAuctionInsight'));
        $this->assertTrue($segments->hasMethod('getAuctionInsightDomain'));
        $this->assertTrue($metrics->hasMethod('getAuctionInsightSearchImpressionShare'));

        // FROM, not anywhere: the file's own docblock names the non-existent
        // resource to explain why it is not queried, so a bare substring check
        // matches the comment documenting the fix.
        $this->assertStringNotContainsString('FROM campaign_auction_insight_result', $source);
        $this->assertStringContainsString('segments.auction_insight_domain', $source);
        $this->assertStringContainsString('metrics.auction_insight_search_impression_share', $source);

        // An INVALID_ARGUMENT is a malformed query, not thin data. Classifying it
        // as "insufficient data" is what hid a report that never worked.
        $this->assertStringNotContainsString('Insufficient data for auction insights', $source);
    }
}

/**
 * Reaches the PartialErrors handling without a SOAP endpoint. The platform kill
 * switch keeps the constructor from authenticating.
 */
class StubbedMicrosoftCampaignService extends MicrosoftCampaignService
{
    public ?array $response = null;

    protected function apiCall(string $operation, array $body): ?array
    {
        return $this->response;
    }
}
