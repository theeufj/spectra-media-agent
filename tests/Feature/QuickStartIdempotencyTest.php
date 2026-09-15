<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pressing Go twice must not create two businesses.
 *
 * Seen live while walking the funnel: two "Yourfirststore" customers a minute
 * apart for the same user and the same website, each with its own half-finished
 * crawl. The knowledge base landed on whichever won, so the other was left with
 * pages but nothing to write a campaign from — and therefore silently failed to
 * qualify for the first campaign it should have been given.
 *
 * A double-click does it, a back-button retry does it, and an impatient second
 * press does it. The website is the natural key.
 */
class QuickStartIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        // resolve() follows redirects to unwrap shorteners.
        Http::fake(['*' => Http::response('<html><title>Your First Store</title></html>', 200)]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_a_second_submission_reuses_the_same_business(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->post(route('quick-start.process'), ['website_url' => 'https://yourfirststore.com'])
            ->assertRedirect(route('quick-start.scanning'));

        $this->actingAs($user)
            ->post(route('quick-start.process'), ['website_url' => 'https://yourfirststore.com'])
            ->assertRedirect(route('quick-start.scanning'));

        $this->assertSame(1, $user->customers()->count(), 'pressing Go twice created two businesses');
    }

    public function test_a_different_website_still_creates_a_second_business(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)->post(route('quick-start.process'), ['website_url' => 'https://yourfirststore.com']);
        $this->actingAs($user)->post(route('quick-start.process'), ['website_url' => 'https://somewhereelse.com']);

        // Agencies and owners of more than one business are the reason this is
        // keyed on the website rather than on the user.
        $this->assertSame(2, $user->customers()->count());
    }

    public function test_another_user_with_the_same_website_is_unaffected(): void
    {
        $first = $this->verifiedUser();
        $second = $this->verifiedUser();

        $this->actingAs($first)->post(route('quick-start.process'), ['website_url' => 'https://yourfirststore.com']);
        $this->actingAs($second)->post(route('quick-start.process'), ['website_url' => 'https://yourfirststore.com']);

        // Scoped to the user's own businesses: two people may legitimately
        // both be working on the same site, and one must not be handed the
        // other's customer record.
        $this->assertSame(1, $first->customers()->count());
        $this->assertSame(1, $second->customers()->count());
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('website', 'like', '%yourfirststore%')->count());
    }
}
