<?php

namespace Tests\Feature;

use App\Jobs\InvitePaidSetupCustomer;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Customers\SetupGoogleIdentity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class SetupGoogleIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private Customer $customer;

    private MockHandler $google;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create(['email' => 'owner+setup@example.com']);
        $this->customer = Customer::factory()->create(['service_type' => 'setup_only',
            'setup_fee_paid_at' => now(), 'google_ads_customer_id' => '1234567890', 'is_sandbox' => false]);
        $this->customer->users()->attach($this->owner, ['role' => 'owner']);
        $this->actingAs($this->owner)->withSession(['active_customer_id' => $this->customer->id]);
        $this->google = new MockHandler;
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(function () {
            return (new GoogleProvider(request(), 'identity-client', 'identity-secret', route('google.callback')))
                ->setHttpClient(new Client(['handler' => HandlerStack::create($this->google)]));
        });
    }

    private function begin(): string
    {
        $this->post(route('customers.setup.google-identity', $this->customer))->assertRedirect();

        return session('state');
    }

    private function successfulGoogleResponse(array $changes = []): void
    {
        $this->google->append(new Response(200, [], json_encode(['access_token' => 'discard-this-token',
            'refresh_token' => 'never-store-this-token', 'expires_in' => 3600], JSON_THROW_ON_ERROR)));
        $this->google->append(new Response(200, [], json_encode(array_replace([
            'sub' => 'verified-subject', 'email' => 'actual-google@example.com', 'email_verified' => true,
            'name' => 'Actual Google Person',
        ], $changes), JSON_THROW_ON_ERROR)));
    }

    public function test_setup_requests_identity_scopes_only_and_supports_inertia_external_navigation(): void
    {
        $response = $this->withHeader('X-Inertia', 'true')->post(route('customers.setup.google-identity', $this->customer));
        $response->assertStatus(409);
        parse_str(parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['openid', 'email'], explode(' ', $query['scope']));
        $this->assertSame('select_account consent', $query['prompt']);
        $this->assertSame('online', $query['access_type']);
        $this->assertSame(route('google.callback'), $query['redirect_uri']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame($this->owner->id, session(SetupGoogleIdentity::SESSION_KEY.'.user_id'));
        $this->assertSame($this->customer->id, session(SetupGoogleIdentity::SESSION_KEY.'.customer_id'));
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_callback_saves_the_verified_google_identity_without_switching_login_or_storing_tokens(): void
    {
        $state = $this->begin();
        $before = User::count();
        $this->successfulGoogleResponse();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertRedirect(route('customers.gtm.setup', $this->customer));

        $identity = $this->customer->fresh()->setup_handover['google_identity'];
        $this->assertSame('actual-google@example.com', $identity['email']);
        $this->assertSame('verified-subject', $identity['subject']);
        $this->assertSame($this->owner->id, $identity['verified_by_user_id']);
        $this->assertNotEmpty($identity['verified_at']);
        $this->assertAuthenticatedAs($this->owner);
        $this->assertSame('owner+setup@example.com', $this->owner->fresh()->email);
        $this->assertSame($before, User::count());
        $stored = json_encode($this->customer->fresh()->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('discard-this-token', $stored);
        $this->assertStringNotContainsString('never-store-this-token', $stored);
        $this->assertNull(session(SetupGoogleIdentity::SESSION_KEY));
        $this->assertNull(session('state'));
        Queue::assertPushed(InvitePaidSetupCustomer::class, 1);
        Mail::assertNothingSent();
    }

    public function test_changing_identity_invalidates_account_access_and_tracking_ownership_but_keeps_the_container(): void
    {
        $this->customer->update(['handover_at' => now(), 'setup_handover' => [
            'google_identity' => ['email' => 'old@example.com', 'subject' => 'old-subject'],
            'ads_access' => ['status' => 'verified'], 'operator_note' => 'keep'],
            'gtm_container_id' => 'GTM-EXISTING', 'gtm_config' => ['ownership' => ['verified' => true], 'container_path' => 'accounts/1/containers/2']]);
        $state = $this->begin();
        $this->successfulGoogleResponse();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))->assertRedirect();
        $customer = $this->customer->fresh();
        $this->assertArrayNotHasKey('ads_access', $customer->setup_handover);
        $this->assertSame('keep', $customer->setup_handover['operator_note']);
        $this->assertNull($customer->handover_at);
        $this->assertArrayNotHasKey('ownership', $customer->gtm_config);
        $this->assertSame('accounts/1/containers/2', $customer->gtm_config['container_path']);
        $this->assertSame('GTM-EXISTING', $customer->gtm_container_id);
    }

    public function test_reconfirming_the_same_identity_preserves_existing_access_and_tracking_proof(): void
    {
        $this->customer->update(['handover_at' => now(), 'setup_handover' => [
            'google_identity' => ['email' => 'actual-google@example.com', 'subject' => 'verified-subject'],
            'ads_access' => ['status' => 'verified']], 'gtm_config' => ['ownership' => ['verified' => true]]]);
        $state = $this->begin();
        $this->successfulGoogleResponse();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))->assertRedirect();
        $customer = $this->customer->fresh();
        $this->assertSame(['status' => 'verified'], $customer->setup_handover['ads_access']);
        $this->assertSame(['verified' => true], $customer->gtm_config['ownership']);
        $this->assertNotNull($customer->handover_at);
    }

    public function test_callback_rejects_a_changed_site_login_without_using_google_or_creating_a_user(): void
    {
        $state = $this->begin();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertRedirect(route('dashboard'))->assertSessionHasErrors('google_identity');
        $this->assertAuthenticatedAs($other);
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertNull(session('state'));
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_callback_rejects_an_expired_intent_without_falling_through_to_login(): void
    {
        $state = $this->begin();
        $this->travel(11)->minutes();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertRedirect(route('dashboard'))->assertSessionHasErrors('google_identity');
        $this->assertAuthenticatedAs($this->owner);
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertNull(session('state'));
    }

    public function test_callback_requires_the_original_authenticated_session(): void
    {
        $state = $this->begin();
        auth()->logout();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('google_identity');
        $this->assertGuest();
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertNull(session('state'));
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_bad_callback_state_binds_nothing_and_stateful_socialite_is_still_required(): void
    {
        $state = $this->begin();
        $this->get(route('google.callback', ['state' => 'wrong-state', 'code' => 'identity-code']))
            ->assertSessionHasErrors('google_identity');
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertAuthenticatedAs($this->owner);

        $state = $this->begin();
        // The intent remains correct, but Socialite's own state is missing.
        $this->session(['state' => null]);
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertSessionHasErrors('google_identity');
        $this->assertNull($this->customer->fresh()->setup_handover);
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_unverified_email_or_missing_subject_never_becomes_handover_identity(): void
    {
        foreach ([['email_verified' => false], ['email_verified' => 'true'], ['sub' => null], ['email' => 'not-an-email']] as $claims) {
            $state = $this->begin();
            $this->successfulGoogleResponse($claims);
            $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
                ->assertSessionHasErrors('google_identity');
            $this->assertNull($this->customer->fresh()->setup_handover);
            $this->assertAuthenticatedAs($this->owner);
        }
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_another_customers_identity_route_is_hidden_and_non_setup_customers_cannot_use_it(): void
    {
        $other = Customer::factory()->create(['service_type' => 'setup_only']);
        $this->post(route('customers.setup.google-identity', $other))->assertNotFound();
        $this->customer->update(['service_type' => 'managed']);
        $this->post(route('customers.setup.google-identity', $this->customer))->assertNotFound();
        $this->assertNull(session(SetupGoogleIdentity::SESSION_KEY));
    }

    public function test_revoked_customer_membership_is_checked_again_on_callback(): void
    {
        $state = $this->begin();
        $this->customer->users()->detach($this->owner);
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))->assertNotFound();
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertNull(session('state'));
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_staff_must_belong_to_the_customer_at_both_identity_start_and_callback(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $this->actingAs($staff);
        $this->post(route('customers.setup.google-identity', $this->customer))
            ->assertRedirect(route('customers.gtm.setup', $this->customer))
            ->assertSessionHasErrors('google_identity');
        $this->assertNull(session(SetupGoogleIdentity::SESSION_KEY));
        $this->assertNull(session('state'));
        $this->assertNull($this->customer->fresh()->setup_handover);

        $this->customer->users()->attach($staff, ['role' => 'owner']);
        $state = $this->begin();
        $this->customer->users()->detach($staff);
        $this->get(route('google.callback', ['state' => $state, 'code' => 'identity-code']))
            ->assertRedirect(route('customers.gtm.setup', $this->customer))
            ->assertSessionHasErrors('google_identity');
        $this->assertNull($this->customer->fresh()->setup_handover);
        $this->assertNull(session('state'));
        $this->assertAuthenticatedAs($staff);
        Queue::assertNotPushed(InvitePaidSetupCustomer::class);
    }

    public function test_normal_google_login_keeps_its_existing_behavior(): void
    {
        $existing = User::factory()->create(['email' => 'actual-google@example.com']);
        $this->begin();
        $this->get(route('google.redirect'))->assertRedirect();
        $this->assertNull(session(SetupGoogleIdentity::SESSION_KEY));
        $state = session('state');
        $this->successfulGoogleResponse();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'login-code']))->assertRedirect();
        $this->assertAuthenticatedAs($existing);
        $this->assertNull($this->customer->fresh()->setup_handover);
        Mail::assertNothingSent();
    }
}
