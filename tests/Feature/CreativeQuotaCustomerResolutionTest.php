<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\CreativeQuotaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Creative quota has to be booked against a customer the caller names.
 *
 * The active customer lives in the session, so it exists only inside a web
 * request. The Stripe webhook and every queued job have none, and the last
 * resort — `$user->customers()->value('customers.id')`, with no ordering — then
 * picked whatever row came back first. A paid boost pack landed on an arbitrary
 * customer of a multi-account buyer that way, and creative_boost_purchases
 * carries no customer_id to correct it afterwards.
 */
class CreativeQuotaCustomerResolutionTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer, Customer} */
    private function userWithTwoCustomers(): array
    {
        $user = User::factory()->create();
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        $user->customers()->attach($first->id, ['role' => 'owner']);
        $user->customers()->attach($second->id, ['role' => 'owner']);

        return [$user, $first, $second];
    }

    public function test_an_explicit_customer_is_used_in_preference_to_the_fallback(): void
    {
        [$user, $first, $second] = $this->userWithTwoCustomers();

        $usage = app(CreativeQuotaService::class)->getOrCreateUsage($user, $second);

        $this->assertEquals(
            $second->id,
            $usage->customer_id,
            'The caller named the customer; the quota must land there and not on whichever row the database returned first.'
        );
        $this->assertNotEquals($first->id, $usage->customer_id);
    }

    public function test_a_customer_the_user_does_not_own_is_refused(): void
    {
        [$user, $first] = $this->userWithTwoCustomers();
        $stranger = Customer::factory()->create();

        $usage = app(CreativeQuotaService::class)->getOrCreateUsage($user, $stranger);

        $this->assertEquals(
            $first->id,
            $usage->customer_id,
            'Ownership is re-checked, so a caller cannot book usage against another tenant.'
        );
    }

    public function test_the_fallback_resolves_to_the_same_customer_every_time(): void
    {
        [$user] = $this->userWithTwoCustomers();

        $service = app(CreativeQuotaService::class);

        $this->assertEquals(
            $service->getOrCreateUsage($user)->customer_id,
            $service->getOrCreateUsage($user)->customer_id,
        );
    }
}
