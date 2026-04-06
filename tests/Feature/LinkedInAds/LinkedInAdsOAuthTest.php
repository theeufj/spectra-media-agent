<?php

namespace Tests\Feature\LinkedInAds;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInAdsOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create([
            'name' => 'OAuth Test Company',
        ]);
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'customer_id' => $this->customer->id,
        ]);

        config([
            'linkedinads.client_id' => 'test-client-id',
            'linkedinads.client_secret' => 'test-client-secret',
            'linkedinads.redirect_uri' => 'https://app.test/auth/linkedin-ads/callback',
        ]);
    }

    public function test_redirect_sends_user_to_linkedin(): void
    {
        $response = $this->actingAs($this->user)->get(route('linkedin-ads.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('linkedin.com/oauth/v2/authorization', $response->headers->get('Location'));
        $this->assertStringContainsString('client_id=test-client-id', $response->headers->get('Location'));
        $this->assertStringContainsString('r_ads', $response->headers->get('Location'));
    }

    public function test_redirect_fails_when_not_configured(): void
    {
        config(['linkedinads.client_id' => null]);

        $response = $this->actingAs($this->user)->get(route('linkedin-ads.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['linkedin_oauth_state' => 'correct-state'])
            ->get(route('linkedin-ads.callback', ['state' => 'wrong-state', 'code' => 'abc']));

        $response->assertRedirect(route('integrations.index'));
        $response->assertSessionHas('error');
    }

    public function test_callback_handles_user_denial(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['linkedin_oauth_state' => 'test-state'])
            ->get(route('linkedin-ads.callback', [
                'state' => 'test-state',
                'error' => 'user_cancelled_authorize',
            ]));

        $response->assertRedirect(route('integrations.index'));
        $response->assertSessionHas('error', 'LinkedIn authorization was denied.');
    }

    public function test_callback_exchanges_code_and_stores_tokens(): void
    {
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'new-access-token',
                'expires_in' => 5184000,
                'refresh_token' => 'new-refresh-token',
            ]),
            'api.linkedin.com/rest/adAccounts*' => Http::response([
                'elements' => [
                    ['id' => '508999888', 'name' => 'Test Ad Account', 'status' => 'ACTIVE'],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['linkedin_oauth_state' => 'valid-state'])
            ->get(route('linkedin-ads.callback', [
                'state' => 'valid-state',
                'code' => 'auth-code-123',
            ]));

        $response->assertRedirect(route('integrations.index'));
        $response->assertSessionHas('success');

        $this->customer->refresh();
        $this->assertEquals('new-access-token', $this->customer->linkedin_oauth_access_token);
        $this->assertEquals('new-refresh-token', $this->customer->linkedin_oauth_refresh_token);
        $this->assertEquals('508999888', $this->customer->linkedin_ads_account_id);
        $this->assertNotNull($this->customer->linkedin_token_expires_at);
    }

    public function test_callback_prompts_account_selection_when_multiple(): void
    {
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'multi-token',
                'expires_in' => 5184000,
            ]),
            'api.linkedin.com/rest/adAccounts*' => Http::response([
                'elements' => [
                    ['id' => '508111', 'name' => 'Account A', 'status' => 'ACTIVE'],
                    ['id' => '508222', 'name' => 'Account B', 'status' => 'ACTIVE'],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['linkedin_oauth_state' => 'valid-state'])
            ->get(route('linkedin-ads.callback', [
                'state' => 'valid-state',
                'code' => 'auth-code-456',
            ]));

        $response->assertSessionHas('linkedin_ad_accounts');
        $accounts = session('linkedin_ad_accounts');
        $this->assertCount(2, $accounts);
    }

    public function test_select_account_stores_account_id(): void
    {
        $this->customer->update([
            'linkedin_oauth_access_token' => 'existing-token',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['linkedin_ad_accounts' => [
                ['id' => '508333', 'name' => 'Chosen Account'],
            ]])
            ->post(route('linkedin-ads.select-account'), [
                'account_id' => '508333',
            ]);

        $response->assertSessionHas('success');
        $this->customer->refresh();
        $this->assertEquals('508333', $this->customer->linkedin_ads_account_id);
    }

    public function test_disconnect_clears_linkedin_fields(): void
    {
        $this->customer->update([
            'linkedin_ads_account_id' => '508444',
            'linkedin_oauth_access_token' => 'some-token',
            'linkedin_oauth_refresh_token' => 'some-refresh',
            'linkedin_token_expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('linkedin-ads.disconnect'));

        $response->assertSessionHas('success');
        $this->customer->refresh();

        $this->assertNull($this->customer->linkedin_ads_account_id);
        $this->assertNull($this->customer->linkedin_oauth_access_token);
        $this->assertNull($this->customer->linkedin_oauth_refresh_token);
        $this->assertNull($this->customer->linkedin_token_expires_at);
    }

    public function test_oauth_routes_require_authentication(): void
    {
        $this->get(route('linkedin-ads.redirect'))->assertRedirect('/login');
        $this->get(route('linkedin-ads.callback'))->assertRedirect('/login');
        $this->post(route('linkedin-ads.select-account'))->assertRedirect('/login');
        $this->post(route('linkedin-ads.disconnect'))->assertRedirect('/login');
    }

    public function test_customer_model_hides_linkedin_tokens(): void
    {
        $this->customer->update([
            'linkedin_oauth_access_token' => 'secret-token',
            'linkedin_oauth_refresh_token' => 'secret-refresh',
        ]);

        $json = $this->customer->refresh()->toArray();

        $this->assertArrayNotHasKey('linkedin_oauth_access_token', $json);
        $this->assertArrayNotHasKey('linkedin_oauth_refresh_token', $json);
    }
}
