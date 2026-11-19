<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /settings/google-api and /settings/facebook-api are the flows Spectra walks
 * Google and Meta through when submitting the app for API verification. They
 * ask for `adwords` and `ads_management` respectively and store the resulting
 * tokens on a per-user Connection row.
 *
 * That is a per-customer OAuth flow — the pattern CLAUDE.md forbids outright,
 * because every ad platform call goes through Spectra's own management account
 * and customer tokens are a credential we must never be holding. The group sat
 * behind `auth` alone under a /settings/ path, so any signed-in customer could
 * walk it and grant us access to their Google Ads account.
 *
 * Nothing links here. It is reached by typing the URL, which is what an admin
 * doing a verification recording does.
 */
class PlatformApiOAuthAccessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'the settings page' => ['get', '/settings/google-api'],
            'the connect redirect' => ['get', '/settings/google-api/connect'],
            'the callback' => ['get', '/settings/google-api/callback'],
            'the verify page' => ['get', '/settings/google-api/verify'],
            'the disconnect action' => ['post', '/settings/google-api/disconnect'],

            'the facebook settings page' => ['get', '/settings/facebook-api'],
            'the facebook connect redirect' => ['get', '/settings/facebook-api/connect'],
            'the facebook callback' => ['get', '/settings/facebook-api/callback'],
            'the facebook verify page' => ['get', '/settings/facebook-api/verify'],
            'the facebook disconnect action' => ['post', '/settings/facebook-api/disconnect'],
            // Creates a real campaign on Meta.
            'the facebook test-campaign action' => ['post', '/settings/facebook-api/test-campaign'],
            'the facebook review pixel action' => ['post', '/settings/facebook-api/review-pixel'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_an_ordinary_customer_cannot_reach_it(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->{$method}($url)
            ->assertForbidden();
    }

    public function test_an_admin_can_still_open_the_pages(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $this->actingAs($admin)->get('/settings/google-api')->assertOk();
        $this->actingAs($admin)->get('/settings/facebook-api')->assertOk();
    }

    public function test_meta_verification_reads_the_selected_account_and_keeps_the_page_token_server_side(): void
    {
        $this->signInWithMetaConnection();

        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_101', 'name' => 'First account'],
                ['id' => 'act_202', 'name' => 'Site to Spend', 'currency' => 'AUD'],
            ]]),
            'graph.facebook.com/*/act_202/insights*' => Http::response(['data' => [
                ['impressions' => '24', 'clicks' => '2'],
            ]]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => '303', 'name' => 'First page', 'access_token' => 'other-page-token'],
                ['id' => '404', 'name' => 'Site to Spend', 'access_token' => 'test-page-token'],
            ]]),
            'graph.facebook.com/*/404/published_posts*' => Http::response(['data' => [
                ['id' => '404_505', 'message' => 'A post published by Site to Spend', 'created_time' => '2026-11-01T01:00:00+0000'],
            ]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => []]),
            'graph.facebook.com/*/me?*' => Http::response(['id' => '1']),
        ]);

        $this->get('/settings/facebook-api/verify?page_id=999')->assertNotFound();

        $this->get('/settings/facebook-api/verify?ad_account_id=act_202&date_preset=maximum&page_id=404')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/FacebookApiVerify')
                ->where('selectedAccount.id', 'act_202')
                ->where('selectedAccount.currency', 'AUD')
                ->where('adInsights.account_id', 'act_202')
                ->where('adInsights.has_activity', true)
                ->where('adInsights.impressions', '24')
                ->where('selectedPageId', '404')
                ->where('pagePosts.page_name', 'Site to Spend')
                ->where('pagePosts.posts.0.message', 'A post published by Site to Spend')
                ->missing('managedPages.pages.0.page_token')
                ->missing('managedPages.pages.1.page_token'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/act_202/insights')
            && $request['date_preset'] === 'maximum');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/404/published_posts')
            && $request['access_token'] === 'test-page-token');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/303/published_posts'));
    }

    public function test_meta_demo_campaign_requires_an_accessible_account_and_starts_paused(): void
    {
        $this->signInWithMetaConnection();

        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_202']]]),
            'graph.facebook.com/*/act_202/campaigns' => Http::response(['id' => '404']),
        ]);

        $this->postJson('/settings/facebook-api/test-campaign', ['ad_account_id' => 'act_101'])
            ->assertForbidden();
        $this->postJson('/settings/facebook-api/test-campaign', ['ad_account_id' => 'act_202'])
            ->assertOk()
            ->assertJsonPath('campaign_id', '404')
            ->assertJsonPath('status', 'PAUSED');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/act_202/campaigns')
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['status'] === 'PAUSED');
    }

    public function test_review_pixel_verifies_business_ownership_and_confirms_the_link(): void
    {
        $this->signInWithMetaConnection();
        config(['services.facebook.business_manager_id' => '101', 'services.facebook.system_user_token' => 'test-system-token']);

        Http::fake([
            'graph.facebook.com/*/act_303?*' => Http::response(['business' => ['id' => '909']]),
            'graph.facebook.com/*/act_202?*' => Http::response(['business' => ['id' => '101']]),
            'graph.facebook.com/*/101/owned_pixels*' => Http::response(['data' => []]),
            'graph.facebook.com/*/101/adspixels' => Http::response(['id' => '707']),
            'graph.facebook.com/*/707/shared_accounts' => Http::response(['success' => true]),
            'graph.facebook.com/*/act_202/adspixels*' => Http::sequence()
                ->push(['data' => [['id' => '606']]])
                ->push(['data' => [['id' => '606'], ['id' => '707']]]),
        ]);

        $this->postJson('/settings/facebook-api/review-pixel', ['business_id' => '101', 'ad_account_id' => 'act_303'])
            ->assertUnprocessable();
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');

        $this->postJson('/settings/facebook-api/review-pixel', ['business_id' => '101', 'ad_account_id' => 'act_202'])
            ->assertOk()->assertJsonPath('pixel_id', '707')->assertJsonPath('linked', true);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/707/shared_accounts')
            && $request->hasHeader('Authorization', 'Bearer test-system-token')
            && $request['account_id'] === '202');
    }

    private function signInWithMetaConnection(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $admin->connections()->create(['platform' => 'facebook_api', 'access_token' => 'test-user-token']);
        $this->actingAs($admin);
    }
}
