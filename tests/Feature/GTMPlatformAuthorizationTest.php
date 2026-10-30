<?php

namespace Tests\Feature;

use App\Http\Controllers\GTMPlatformAuthorizationController;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Services\GTM\GTMPlatformCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GTMPlatformAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Connection $existing;

    private Connection $ads;

    private MockHandler $google;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'tracking-client', 'services.google.client_secret' => 'tracking-secret',
            'services.gtm.platform_account_id' => '123456', 'services.gtm.platform_refresh_token' => 'environment-fallback',
            'services.gtm.platform_email' => 'fallback@example.com', 'googleads.mcc_refresh_token' => 'unchanged-mcc-token']);
        $this->admin = $this->admin();
        $this->actingAs($this->admin);
        $this->existing = Connection::create(['platform' => 'gtm_platform', 'user_id' => $this->admin->id,
            'account_id' => '123456', 'account_name' => 'previous@example.com', 'refresh_token' => 'old-refresh',
            'access_token' => 'old-access', 'scopes' => GTMPlatformCredential::SCOPES]);
        $this->ads = Connection::create(['platform' => 'google_api', 'user_id' => $this->admin->id,
            'account_id' => 'ads-admin', 'refresh_token' => 'ads-refresh', 'access_token' => 'ads-access']);
        $this->ads->refresh();
        $this->google = new MockHandler;
        Socialite::shouldReceive('driver')->with('google')->andReturnUsing(fn () => (new GoogleProvider(request(), 'tracking-client', 'tracking-secret', route('google-api.callback')))
            ->setHttpClient(new Client(['handler' => HandlerStack::create($this->google)])));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    private function begin(): string
    {
        $this->post(route('google-api.tracking-authorize'))->assertRedirect();

        return session('state');
    }

    private function googleResponses(array $claims = [], array $tokenChanges = []): void
    {
        $this->google->append(new Response(200, [], json_encode(array_replace([
            'access_token' => 'new-platform-access', 'refresh_token' => 'new-platform-refresh', 'expires_in' => 3600,
        ], $tokenChanges), JSON_THROW_ON_ERROR)));
        $this->google->append(new Response(200, [], json_encode(array_replace([
            'sub' => 'actual-google-subject', 'email' => 'actual-admin@example.com', 'email_verified' => true,
            'name' => 'Google Administrator',
        ], $claims), JSON_THROW_ON_ERROR)));
    }

    private function apiResponses(array $info = [], string $account = '123456', string $permission = 'admin'): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response(array_replace([
                'aud' => 'tracking-client', 'email' => 'actual-admin@example.com',
                'scope' => implode(' ', GTMPlatformCredential::SCOPES),
            ], $info)),
            'https://tagmanager.googleapis.com/tagmanager/v2/accounts/123456' => Http::response(['accountId' => $account]),
            'https://tagmanager.googleapis.com/tagmanager/v2/accounts/123456/user_permissions*' => Http::response([
                'userPermission' => [['accountId' => '123456', 'emailAddress' => 'actual-admin@example.com', 'accountAccess' => ['permission' => $permission]]],
            ]),
        ]);
    }

    private function completeAuthorization(string $state)
    {
        return $this->get(route('google-api.callback', ['state' => $state, 'code' => 'platform-code']));
    }

    private function assertCredentialPreserved(): void
    {
        $this->assertSame('old-refresh', $this->existing->fresh()->getAttribute('refresh_token'));
        $this->assertSame('old-access', $this->existing->fresh()->getAttribute('access_token'));
        $this->assertSame(1, Connection::where('platform', 'gtm_platform')->count());
        $this->assertSame($this->ads->getAttributes(), $this->ads->fresh()->getAttributes());
        $this->assertSame('unchanged-mcc-token', config('googleads.mcc_refresh_token'));
    }

    public function test_only_a_full_platform_admin_can_start_tracking_authorization(): void
    {
        $ordinary = User::factory()->create();
        $this->actingAs($ordinary)->post(route('google-api.tracking-authorize'))->assertForbidden();
        $this->get(route('google-api.callback', ['state' => 'forged', 'code' => 'code']))->assertForbidden();
        $ordinary->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'support'])));
        $this->actingAs($ordinary->fresh())->post(route('google-api.tracking-authorize'))->assertForbidden();
        $this->assertNull(session(GTMPlatformAuthorizationController::SESSION_KEY));
        $this->assertCredentialPreserved();
        Http::assertNothingSent();
    }

    public function test_start_requests_only_tracking_and_identity_scopes_at_the_existing_callback(): void
    {
        $response = $this->withHeader('X-Inertia', 'true')->post(route('google-api.tracking-authorize'));
        $response->assertStatus(409);
        parse_str(parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);
        $this->assertSame(GTMPlatformCredential::SCOPES, explode(' ', $query['scope']));
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('select_account consent', $query['prompt']);
        $this->assertSame(route('google-api.callback'), $query['redirect_uri']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame($this->admin->id, session(GTMPlatformAuthorizationController::SESSION_KEY.'.user_id'));
        $this->assertSame('123456', session(GTMPlatformAuthorizationController::SESSION_KEY.'.account_id'));
        $this->assertCredentialPreserved();
    }

    public function test_invalid_or_missing_socialite_state_never_replaces_the_credential(): void
    {
        $this->begin();
        $this->completeAuthorization('wrong-state')->assertRedirect(route('google-api.show'))->assertSessionHasErrors('oauth');
        $this->assertNull(session(GTMPlatformAuthorizationController::SESSION_KEY));
        $this->assertNull(session('state'));

        $state = $this->begin();
        $this->session(['state' => null]);
        $this->completeAuthorization($state)->assertRedirect(route('google-api.show'))->assertSessionHasErrors('oauth');
        $this->assertCredentialPreserved();
        Http::assertNothingSent();
    }

    public function test_expired_intent_or_changed_platform_account_is_rejected_before_google_calls(): void
    {
        $state = $this->begin();
        $this->travel(11)->minutes();
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->travelBack();
        $state = $this->begin();
        config(['services.gtm.platform_account_id' => '654321']);
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->assertCredentialPreserved();
        Http::assertNothingSent();
    }

    public function test_changed_admin_identity_and_revoked_write_access_are_checked_again_at_callback(): void
    {
        $state = $this->begin();
        $this->actingAs($this->admin());
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->actingAs($this->admin);
        $state = $this->begin();
        $this->admin->roles()->sync([Role::unguarded(fn () => Role::firstOrCreate(['name' => 'support']))->id]);
        $this->actingAs($this->admin->fresh());
        $this->completeAuthorization($state)->assertForbidden();
        $this->assertCredentialPreserved();
        Http::assertNothingSent();
    }

    public static function invalidTokenEvidence(): array
    {
        $missing = array_values(array_diff(GTMPlatformCredential::SCOPES, ['https://www.googleapis.com/auth/tagmanager.manage.users']));

        return ['missing user-management scope' => [['scope' => implode(' ', $missing)]],
            'wrong client' => [['aud' => 'different-client']], 'wrong identity' => [['email' => 'other@example.com']]];
    }

    #[DataProvider('invalidTokenEvidence')]
    public function test_missing_scopes_or_mismatched_token_identity_keep_the_previous_credential(array $info): void
    {
        $state = $this->begin();
        $this->googleResponses();
        $this->apiResponses($info);
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->assertCredentialPreserved();
    }

    public static function invalidGoogleClaims(): array
    {
        return ['unverified email' => [['email_verified' => false], []], 'missing subject' => [['sub' => null], []],
            'missing offline token' => [[], ['refresh_token' => null]]];
    }

    #[DataProvider('invalidGoogleClaims')]
    public function test_unverified_email_or_absent_offline_token_cannot_replace_the_credential(array $claims, array $tokens): void
    {
        $state = $this->begin();
        $this->googleResponses($claims, $tokens);
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->assertCredentialPreserved();
        Http::assertNothingSent();
    }

    public function test_wrong_account_or_non_admin_google_permissions_cannot_replace_the_credential(): void
    {
        foreach ([['654321', 'admin'], ['123456', 'user']] as [$account, $permission]) {
            $state = $this->begin();
            $this->googleResponses();
            $this->apiResponses(account: $account, permission: $permission);
            $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
            $this->assertCredentialPreserved();
        }
    }

    public function test_admin_revocation_during_google_checks_is_rechecked_before_storing_the_token(): void
    {
        $state = $this->begin();
        $this->googleResponses();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'tokeninfo')) {
                return Http::response(['aud' => 'tracking-client', 'email' => 'actual-admin@example.com', 'scope' => implode(' ', GTMPlatformCredential::SCOPES)]);
            }
            if (str_contains($request->url(), 'user_permissions')) {
                $this->admin->roles()->detach();

                return Http::response(['userPermission' => [['accountId' => '123456', 'emailAddress' => 'actual-admin@example.com', 'accountAccess' => ['permission' => 'admin']]]]);
            }

            return Http::response(['accountId' => '123456']);
        });
        $this->completeAuthorization($state)->assertSessionHasErrors('oauth');
        $this->assertCredentialPreserved();
    }

    public function test_success_replaces_one_encrypted_shared_credential_without_changing_ads_credentials(): void
    {
        $state = $this->begin();
        $this->googleResponses();
        $this->apiResponses();
        $this->completeAuthorization($state)->assertRedirect(route('google-api.show'))->assertSessionHasNoErrors();
        $connection = Connection::where('platform', 'gtm_platform')->sole();
        $this->assertSame($this->existing->id, $connection->id);
        $this->assertSame($this->admin->id, $connection->user_id);
        $this->assertSame('123456', $connection->account_id);
        $this->assertSame('actual-admin@example.com', $connection->account_name);
        $this->assertSame('new-platform-refresh', $connection->getAttribute('refresh_token'));
        $this->assertSame('new-platform-refresh', Crypt::decryptString($connection->getRawOriginal('refresh_token')));
        $this->assertSame('new-platform-access', Crypt::decryptString($connection->getRawOriginal('access_token')));
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertSame($this->ads->getAttributes(), $this->ads->fresh()->getAttributes());
        $this->assertSame('unchanged-mcc-token', config('googleads.mcc_refresh_token'));
        $this->assertNull(session(GTMPlatformAuthorizationController::SESSION_KEY));
        $this->assertNull(session('state'));
        $credential = new GTMPlatformCredential;
        $this->assertSame('new-platform-refresh', $credential->refreshToken());
        $this->assertSame('actual-admin@example.com', $credential->email());
        $this->assertTrue($credential->summary()['ownership_checks_authorized']);
    }

    public function test_shared_credential_requires_matching_account_and_verified_scope_set(): void
    {
        $credential = new GTMPlatformCredential;
        $this->assertSame('old-refresh', $credential->refreshToken());
        $this->existing->update(['scopes' => ['https://www.googleapis.com/auth/tagmanager.readonly']]);
        $this->assertFalse($credential->summary()['ownership_checks_authorized']);
        $this->assertSame('environment-fallback', $credential->refreshToken());
        $this->existing->update(['scopes' => GTMPlatformCredential::SCOPES, 'account_id' => '654321']);
        $this->assertFalse($credential->summary()['ownership_checks_authorized']);
        $this->assertSame('fallback@example.com', $credential->email());
    }
}
