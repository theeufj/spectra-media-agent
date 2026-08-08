<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Services\LinkedInAds\CampaignService as LinkedInCampaignService;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Disabling a platform in the admin UI must actually stop it calling out.
 *
 * It previously did not. EnabledPlatform was consulted in exactly two places —
 * the campaign wizard and strategy generation — while every scheduled job gated
 * on whether a customer or campaign happened to hold a platform ID. So switching
 * Microsoft off stopped you creating new Microsoft campaigns, and health checks,
 * self-healing and performance fetches carried on hitting its API regardless.
 */
class PlatformKillSwitchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('enabled_platform_slugs');
    }

    private function platform(string $name, string $slug, bool $enabled): void
    {
        EnabledPlatform::updateOrCreate(['slug' => $slug], [
            'name' => $name,
            'is_enabled' => $enabled,
        ]);
        Cache::forget('enabled_platform_slugs');
    }

    public function test_disabled_microsoft_makes_no_outbound_calls(): void
    {
        $this->platform('Microsoft', 'microsoft', false);
        Http::fake();

        new MicrosoftCampaignService(Customer::factory()->create());

        Http::assertNothingSent();
    }

    public function test_disabled_linkedin_makes_no_outbound_calls(): void
    {
        $this->platform('LinkedIn', 'linkedin', false);
        Http::fake();

        new LinkedInCampaignService(Customer::factory()->create());

        Http::assertNothingSent();
    }

    public function test_the_switch_accepts_the_naming_variants_used_across_the_codebase(): void
    {
        $this->platform('Microsoft', 'microsoft', false);

        // Call sites variously say 'microsoft', 'Microsoft' and 'microsoft_ads'.
        $this->assertFalse(EnabledPlatform::isEnabled('microsoft'));
        $this->assertFalse(EnabledPlatform::isEnabled('Microsoft'));
        $this->assertFalse(EnabledPlatform::isEnabled('microsoft_ads'));
    }

    public function test_an_unknown_platform_defaults_to_enabled(): void
    {
        // Adding an integration without seeding a row must not silently disable it.
        $this->assertTrue(EnabledPlatform::isEnabled('some-new-platform'));
    }

    public function test_toggling_takes_effect_without_waiting_for_the_cache(): void
    {
        $this->platform('Microsoft', 'microsoft', true);
        $this->assertTrue(EnabledPlatform::isEnabled('microsoft'));

        // Saving the model busts the cache, so an admin toggle applies immediately.
        EnabledPlatform::where('slug', 'microsoft')->first()->update(['is_enabled' => false]);

        $this->assertFalse(EnabledPlatform::isEnabled('microsoft'));
    }
}
