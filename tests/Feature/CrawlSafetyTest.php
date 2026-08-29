<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Url;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Everything a user types as "their website" gets fetched by OUR servers.
 * The crawler must refuse anything that isn't a publicly routable host —
 * cloud metadata endpoints, localhost, the private network — and the
 * expensive endpoints must be rate limited.
 */
class CrawlSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_and_reserved_hosts_are_not_safe(): void
    {
        foreach ([
            'http://127.0.0.1/admin',
            'https://localhost',
            'https://169.254.169.254/metadata/v1',
            'http://10.0.0.5/',
            'https://192.168.1.1',
            'https://172.16.0.9/x',
            'https://internal-api.local',
            'not a url at all',
        ] as $bad) {
            $this->assertFalse(Url::isSafePublicHost($bad), "should reject: {$bad}");
        }
    }

    public function test_public_hosts_are_safe(): void
    {
        $this->assertTrue(Url::isSafePublicHost('https://8.8.8.8/'));
        $this->assertTrue(Url::isSafePublicHost('https://example.com'));
    }

    public function test_quick_start_refuses_to_scan_the_metadata_endpoint(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/quick-start', ['website_url' => 'http://169.254.169.254/metadata/v1'])
            ->assertSessionHasErrors('website_url');

        $this->assertSame(0, $user->customers()->count());
    }

    public function test_the_scan_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();

        // Burn through the limit with invalid submissions.
        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->post('/quick-start', ['website_url' => 'not-a-url']);
        }

        $this->actingAs($user)
            ->post('/quick-start', ['website_url' => 'https://example.com'])
            ->assertStatus(429);
    }
}
