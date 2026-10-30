<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use App\Services\GTM\CustomerTrackingOwnership;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerTrackingOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private User $owner;

    private array $container;

    private array $permissions;

    private array $workspaces;

    private bool $denyPermissions = false;

    private bool $paginate = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test',
            'services.gtm.platform_refresh_token' => 'test', 'services.gtm.platform_account_id' => '111',
            'services.gtm.platform_email' => 'platform@example.com']);
        $this->owner = User::factory()->create();
        $this->customer = Customer::factory()->create(['service_type' => 'setup_only', 'setup_fee_paid_at' => now(), 'is_sandbox' => false,
            'gtm_container_id' => null, 'gtm_account_id' => null, 'gtm_workspace_id' => null, 'gtm_config' => null,
            'setup_handover' => ['google_identity' => ['email' => 'customer@example.com', 'subject' => 'customer-subject',
                'verified_at' => now()->toIso8601String(), 'verified_by_user_id' => $this->owner->id]]]);
        $this->customer->users()->attach($this->owner, ['role' => 'owner']);
        $this->container = ['accountId' => '123', 'containerId' => '456', 'path' => 'accounts/123/containers/456',
            'publicId' => 'GTM-CUSTOMER', 'name' => 'My business', 'usageContext' => ['web']];
        $this->permissions = [
            ['accountId' => '123', 'emailAddress' => 'CUSTOMER@example.com', 'accountAccess' => ['permission' => 'admin']],
            ['accountId' => '123', 'emailAddress' => 'platform@example.com', 'accountAccess' => ['permission' => 'admin'],
                'containerAccess' => [['containerId' => '456', 'permission' => 'publish']]],
        ];
        $this->workspaces = [['workspaceId' => '7', 'name' => 'Default Workspace', 'path' => 'accounts/123/containers/456/workspaces/7']];
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'platform-access']);
            }
            if ($request->method() !== 'GET') {
                throw new \RuntimeException('Ownership checks must never mutate Google.');
            }
            if (str_contains($request->url(), '/user_permissions')) {
                if ($this->denyPermissions) {
                    return Http::response(['error' => ['message' => 'Permission denied']], 403);
                }
                if ($this->paginate && ! str_contains($request->url(), 'pageToken=')) {
                    return Http::response(['userPermission' => [$this->permissions[0]], 'nextPageToken' => 'next+page']);
                }

                return Http::response(['userPermission' => $this->paginate ? [$this->permissions[1]] : $this->permissions]);
            }
            if (str_ends_with($request->url(), '/workspaces')) {
                return Http::response(['workspace' => $this->workspaces]);
            }
            if (str_ends_with($request->url(), '/containers/456')) {
                return Http::response($this->container);
            }
            throw new \RuntimeException('Unexpected Google request: '.$request->url());
        });
    }

    private function connect(): array
    {
        return app(CustomerTrackingOwnership::class)->connect($this->customer, '123', '456');
    }

    private function rejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected unsafe tracking selection to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function test_connect_reads_all_permission_pages_and_stores_owned_binding_without_conversion_claim(): void
    {
        $this->paginate = true;
        $result = $this->connect();
        $saved = $this->customer->fresh();
        $this->assertTrue($result['connected']);
        $this->assertSame('456', $result['container_id']);
        $this->assertSame('GTM-CUSTOMER', $result['public_id']);
        $this->assertSame($saved->uuid, $saved->gtm_config['customer_uuid']);
        $this->assertSame('customer-subject', $saved->gtm_config['ownership']['subject']);
        $this->assertFalse($saved->gtm_installed);
        $this->assertNull($saved->conversion_tracking_verified_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pageToken=next%2Bpage'));
        $this->assertArrayNotHasKey('refresh_token', $saved->gtm_config);
    }

    public function test_shared_platform_container_is_rejected_before_google_call(): void
    {
        $this->rejected(fn () => app(CustomerTrackingOwnership::class)->connect($this->customer, '111', '456'), 'shared SiteToSpend');
        Http::assertNothingSent();
    }

    public function test_removed_identity_verifier_cannot_connect_or_reuse_cached_ownership(): void
    {
        $this->connect();
        $this->customer->users()->detach($this->owner);
        $this->assertFalse(app(CustomerTrackingOwnership::class)->status($this->customer->fresh())['connected']);
        $this->rejected(fn () => $this->connect(), 'Confirm your Google identity');
    }

    public function test_customer_must_be_account_admin_and_platform_must_have_publish(): void
    {
        $this->permissions[0]['accountAccess']['permission'] = 'user';
        $this->rejected(fn () => $this->connect(), 'must be an administrator');
        $this->permissions[0]['accountAccess']['permission'] = 'admin';
        $this->permissions[1]['containerAccess'][0]['permission'] = 'edit';
        $this->rejected(fn () => $this->connect(), 'Publish access');
        $this->assertNull($this->customer->fresh()->gtm_container_id);
    }

    public function test_same_domain_or_archived_customer_does_not_allow_reusing_its_container(): void
    {
        $other = Customer::factory()->create(['website' => $this->customer->website, 'gtm_container_id' => 'GTM-CUSTOMER',
            'gtm_config' => ['container_path' => 'accounts/123/containers/456']]);
        $other->delete();
        $this->rejected(fn () => $this->connect(), 'already assigned to another business');
        $this->assertNull($this->customer->fresh()->gtm_container_id);
    }

    public function test_container_identity_web_context_and_workspace_must_match(): void
    {
        $this->container['usageContext'] = ['android'];
        $this->rejected(fn () => $this->connect(), 'selected web container');
        $this->container['usageContext'] = ['web'];
        $this->container['path'] = 'accounts/999/containers/456';
        $this->rejected(fn () => $this->connect(), 'selected web container');
        $this->container['path'] = 'accounts/123/containers/456';
        $this->workspaces[0]['path'] = 'accounts/999/containers/456/workspaces/7';
        $this->rejected(fn () => $this->connect(), 'usable workspace');
        $this->assertNull($this->customer->fresh()->gtm_container_id);
    }

    public function test_missing_management_scope_cannot_bind_the_public_container(): void
    {
        $this->denyPermissions = true;
        $this->rejected(fn () => $this->connect(), 'permissions could not be verified');
        $this->assertNull($this->customer->fresh()->gtm_container_id);
    }

    public function test_fresh_verification_preserves_installed_snippet_and_goal_but_revoked_access_invalidates_proof(): void
    {
        $this->connect();
        $this->customer->refresh()->update(['gtm_installed' => true, 'gtm_last_verified' => now(), 'handover_at' => now(),
            'setup_handover' => [...$this->customer->setup_handover, 'completed_at' => now()->toIso8601String()]]);
        $result = app(CustomerTrackingOwnership::class)->verify($this->customer);
        $this->assertTrue($result['connected']);
        $this->assertTrue($this->customer->fresh()->gtm_installed);
        $this->assertNotNull($this->customer->fresh()->handover_at);
        $this->assertNotEmpty($this->customer->fresh()->setup_handover['completed_at']);
        $this->permissions[0]['accountAccess']['permission'] = 'user';
        $this->rejected(fn () => app(CustomerTrackingOwnership::class)->verify($this->customer), 'must be an administrator');
        $this->assertFalse(app(CustomerTrackingOwnership::class)->status($this->customer->fresh())['connected']);
        $this->assertNull($this->customer->fresh()->handover_at);
        $this->assertArrayNotHasKey('completed_at', $this->customer->fresh()->setup_handover);
        $this->assertSame('GTM-CUSTOMER', $this->customer->fresh()->gtm_container_id);
        $this->permissions[0]['accountAccess']['permission'] = 'admin';
        $this->assertTrue(app(CustomerTrackingOwnership::class)->verify($this->customer)['connected']);
    }

    public function test_explicit_container_change_invalidates_website_goals_without_touching_google_tags(): void
    {
        $this->customer->update(['gtm_container_id' => 'GTM-OLD', 'gtm_account_id' => '111', 'gtm_workspace_id' => '1',
            'gtm_installed' => true, 'handover_at' => now(), 'gtm_config' => ['container_path' => 'accounts/111/containers/222'],
            'setup_handover' => [...$this->customer->setup_handover, 'completed_at' => now()->toIso8601String()]]);
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'execution_result' => ['metadata' => ['setup_conversion_goal' => [
            'request_id' => 'old-request', 'status' => 'configured', 'goal' => ['trigger' => 'event'], 'managed_tag_may_exist' => true]]]]);
        $this->connect();
        $goal = $strategy->fresh()->execution_result['metadata']['setup_conversion_goal'];
        $this->assertSame('needs_review', $goal['status']);
        $this->assertNull($goal['request_id']);
        $this->assertTrue($goal['managed_tag_may_exist']);
        $this->assertSame('accounts/111/containers/222', $goal['managed_tag_container_path']);
        $this->assertFalse($this->customer->fresh()->gtm_installed);
        $this->assertNull($this->customer->fresh()->handover_at);
        $this->assertArrayNotHasKey('completed_at', $this->customer->fresh()->setup_handover);
        $this->connect();
        $this->assertSame($goal, $strategy->fresh()->execution_result['metadata']['setup_conversion_goal']);
    }

    public function test_setup_only_provisioning_never_creates_a_platform_owned_container(): void
    {
        $result = app(GTMContainerService::class)->provisionContainerForCustomer($this->customer);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('own Google Tag Manager', $result['error']);
        Http::assertNothingSent();
    }

    public function test_owned_binding_is_required_before_reviewed_tag_writes(): void
    {
        $this->customer->update(['gtm_account_id' => '123', 'gtm_container_id' => 'GTM-CUSTOMER', 'gtm_workspace_id' => '7',
            'gtm_config' => ['customer_uuid' => $this->customer->uuid, 'container_path' => 'accounts/123/containers/456']]);
        $result = app(GTMContainerService::class)->configureReviewedConversionTag($this->customer, '01a16781-64fe-7215-bf44-7024efab6990',
            ['objective' => 'lead', 'trigger' => 'event', 'event_name' => 'success'], 'AW-12345', 'label');
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_connect_endpoint_authorizes_paid_setup_owner_and_exposes_validation_errors(): void
    {
        $this->actingAs($this->owner)->withSession(['active_customer_id' => $this->customer->id])
            ->post(route('customers.gtm.connect', $this->customer), ['account_id' => '123', 'container_id' => 'GTM-CUSTOMER'])
            ->assertSessionHasErrors('container_id');
        $this->post(route('customers.gtm.connect', $this->customer), ['account_id' => '123', 'container_id' => '456'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('GTM-CUSTOMER', $this->customer->fresh()->gtm_container_id);
    }

    public function test_all_generic_mutating_paths_reject_setup_and_customer_owned_containers_before_http(): void
    {
        $gtm = app(GTMContainerService::class);
        foreach ([['service_type' => 'setup_only', 'gtm_config' => []],
            ['service_type' => 'managed', 'gtm_config' => ['ownership' => ['type' => 'customer']]]] as $state) {
            $this->customer->update($state + ['gtm_account_id' => '123', 'gtm_container_id' => 'GTM-CUSTOMER', 'gtm_workspace_id' => '7']);
            $operations = [
                fn () => $gtm->addConversionTag($this->customer, 'Generic conversion', '12345'),
                fn () => $gtm->addFacebookPixelTag($this->customer, '12345'),
                fn () => $gtm->addFacebookConversionTag($this->customer, '12345'),
                fn () => $gtm->addMicrosoftUetTag($this->customer, '12345'),
                fn () => $gtm->addTrigger($this->customer, 'Generic trigger', 'form_submit'),
                fn () => $gtm->publishContainer($this->customer),
                fn () => $gtm->addFacebookLeadEventTag($this->customer, '12345'),
                fn () => $gtm->addMicrosoftLeadEventTag($this->customer, '12345'),
            ];
            foreach ($operations as $operation) {
                $result = $operation();
                $this->assertFalse($result['success']);
                $this->assertStringContainsString('reviewed conversion goal', $result['error']);
            }
        }
        Http::assertNothingSent();
    }
}
