<?php

namespace Tests\Feature;

use App\Jobs\SendGoogleAdsLinkInvitation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Collecting the customer's existing Google Ads account during onboarding.
 *
 * CustomerObserver sends a link invitation the moment google_ads_customer_id is
 * set, but until now no interface ever set it: the create form did not ask, the
 * store validator would have stripped the field, and the edit page displayed the
 * id read-only. The observer was watching something nothing wrote, so the whole
 * linking path was unreachable from the product.
 *
 * Two entry points matter, because customers rarely have the id to hand at
 * sign-up: on the create form, and later on the profile.
 */
class GoogleAdsAccountOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    public function test_the_create_form_accepts_an_account_id_and_requests_a_link(): void
    {
        Queue::fake();

        $this->actingAs($this->actingUser())->post(route('customers.store'), [
            'name' => 'Acme',
            'country' => 'AU',
            'timezone' => 'Australia/Sydney',
            'google_ads_customer_id' => '9636173260',
        ]);

        $this->assertDatabaseHas('customers', ['google_ads_customer_id' => '9636173260']);
        Queue::assertPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_the_displayed_dashed_format_is_accepted(): void
    {
        // Google shows ids as 123-456-7890, so that is how they get copied.
        Queue::fake();

        $this->actingAs($this->actingUser())->post(route('customers.store'), [
            'name' => 'Acme',
            'country' => 'AU',
            'timezone' => 'Australia/Sydney',
            'google_ads_customer_id' => '963-617-3260',
        ]);

        $this->assertDatabaseHas('customers', ['google_ads_customer_id' => '9636173260']);
    }

    public function test_a_malformed_id_is_rejected_with_a_usable_message(): void
    {
        Queue::fake();

        $this->actingAs($this->actingUser())
            ->post(route('customers.store'), [
                'name' => 'Acme',
                'country' => 'AU',
                'timezone' => 'Australia/Sydney',
                'google_ads_customer_id' => '12345',
            ])
            ->assertSessionHasErrors('google_ads_customer_id');

        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_leaving_it_blank_creates_the_customer_without_inviting(): void
    {
        // The default path: no existing account, so we create one for them.
        Queue::fake();

        $this->actingAs($this->actingUser())->post(route('customers.store'), [
            'name' => 'Acme',
            'country' => 'AU',
            'timezone' => 'Australia/Sydney',
            'google_ads_customer_id' => '',
        ]);

        $this->assertDatabaseHas('customers', ['name' => 'Acme', 'google_ads_customer_id' => null]);
        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_the_id_can_be_added_later_from_the_profile(): void
    {
        // The common case — most customers do not have it to hand at sign-up.
        Queue::fake();

        $user = $this->actingUser();
        $customer = Customer::factory()->create(['google_ads_customer_id' => null, 'is_sandbox' => false]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'google_ads_customer_id' => '963-617-3260',
        ]);

        $this->assertSame('9636173260', $customer->fresh()->google_ads_customer_id);
        Queue::assertPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_blanking_the_field_does_not_erase_a_stored_id(): void
    {
        // An edit form that omits or empties the field is not a request to
        // disconnect the account.
        Queue::fake();

        $user = $this->actingUser();
        $customer = Customer::factory()->create(['google_ads_customer_id' => '9636173260']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'name' => $customer->name,
            'google_ads_customer_id' => '',
        ]);

        $this->assertSame('9636173260', $customer->fresh()->google_ads_customer_id);
    }

    public function test_a_linked_account_cannot_be_repointed_by_editing_the_profile(): void
    {
        // Campaigns reference the linked account. Swapping the id while the link
        // is live would leave them pointing at an account we no longer manage.
        Queue::fake();

        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '9636173260',
            'google_ads_link_status' => 'active',
        ]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->put(route('customers.update', $customer), [
                'name' => $customer->name,
                'google_ads_customer_id' => '1234567890',
            ])
            ->assertSessionHasErrors('google_ads_customer_id');

        $this->assertSame('9636173260', $customer->fresh()->google_ads_customer_id);
    }
}
