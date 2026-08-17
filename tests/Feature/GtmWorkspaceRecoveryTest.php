<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A published container must stay writable.
 *
 * Publishing consumes the workspace two different ways, and only one was
 * handled. GTM either freezes it — "Workspace is already submitted" — or removes
 * it outright and leaves a fresh Default Workspace behind. In the second case
 * the id stored on the customer points at nothing, every later write 404s, and
 * rotation never fired because the error did not say "already submitted".
 *
 * That stranded the sitetospend record after a real publish: the workspace was
 * gone, the tag updates failed, and the record had to be repointed by hand.
 */
class GtmWorkspaceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.gtm.platform_refresh_token' => 'test-refresh',
            'services.gtm.platform_account_id' => '6351790509',
        ]);
    }

    private function customer(string $workspaceId = '12'): Customer
    {
        return Customer::factory()->create([
            'website' => 'https://sitetospend.com',
            'gtm_container_id' => 'GTM-TEST123',
            'gtm_account_id' => '6351790509',
            'gtm_workspace_id' => $workspaceId,
            'gtm_config' => ['container_path' => 'accounts/6351790509/containers/250472733'],
        ]);
    }

    public function test_a_deleted_workspace_is_recovered_from(): void
    {
        // The case that was missing: the stored workspace no longer exists, so
        // the write 404s rather than reporting "already submitted".
        $customer = $this->customer('12');
        $attempts = 0;

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/workspaces/12/tags' => Http::response(['error' => ['message' => 'Not Found']], 404),
            '*/workspaces' => Http::response(['workspace' => [
                ['workspaceId' => '16', 'name' => 'Default Workspace'],
            ]]),
            '*/workspaces/16/tags' => function () use (&$attempts) {
                $attempts++;

                return Http::response(['tagId' => '99']);
            },
            '*/triggers' => Http::response(['trigger' => []]),
            '*' => Http::response([]),
        ]);

        $result = app(GTMContainerService::class)->addFacebookPixelTag($customer, '1234567890');

        $this->assertTrue($result['success'], 'the write should retry in a usable workspace');
        $this->assertSame(1, $attempts);
    }

    public function test_the_new_workspace_is_remembered(): void
    {
        // Otherwise every subsequent write repeats the same 404 and recovery.
        $customer = $this->customer('12');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/workspaces/12/tags' => Http::response(['error' => ['message' => 'Not Found']], 404),
            '*/workspaces' => Http::response(['workspace' => [
                ['workspaceId' => '16', 'name' => 'Default Workspace'],
            ]]),
            '*/workspaces/16/tags' => Http::response(['tagId' => '99']),
            '*/triggers' => Http::response(['trigger' => []]),
            '*' => Http::response([]),
        ]);

        app(GTMContainerService::class)->addFacebookPixelTag($customer, '1234567890');

        $this->assertSame('16', $customer->fresh()->gtm_workspace_id);
    }

    public function test_a_frozen_workspace_is_still_recovered_from(): void
    {
        // The original case must keep working.
        $customer = $this->customer('12');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/workspaces/12/tags' => Http::response(['error' => ['message' => 'Workspace is already submitted.']], 400),
            '*/workspaces' => Http::response(['workspace' => [
                ['workspaceId' => '16', 'name' => 'Default Workspace'],
            ]]),
            '*/workspaces/16/tags' => Http::response(['tagId' => '99']),
            '*/triggers' => Http::response(['trigger' => []]),
            '*' => Http::response([]),
        ]);

        $result = app(GTMContainerService::class)->addFacebookPixelTag($customer, '1234567890');

        $this->assertTrue($result['success']);
        $this->assertSame('16', $customer->fresh()->gtm_workspace_id);
    }

    public function test_an_ordinary_failure_does_not_rotate(): void
    {
        // Rotating on any error would burn through the per-container workspace
        // budget for problems that have nothing to do with the workspace.
        $customer = $this->customer('12');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            '*/workspaces/12/tags' => Http::response(['error' => ['message' => 'Invalid tag configuration']], 400),
            '*/workspaces' => Http::response(['workspace' => [
                ['workspaceId' => '16', 'name' => 'Default Workspace'],
            ]]),
            '*/triggers' => Http::response(['trigger' => []]),
            '*' => Http::response([]),
        ]);

        $result = app(GTMContainerService::class)->addFacebookPixelTag($customer, '1234567890');

        $this->assertFalse($result['success']);
        $this->assertSame('12', $customer->fresh()->gtm_workspace_id, 'the workspace should not have rotated');
    }
}
