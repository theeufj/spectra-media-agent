<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * URLs must not count the business for anyone who sees one.
 *
 * `/customers/23/edit` tells a screenshot, a support ticket, a shared link or a
 * referrer header roughly how many customers there are. Ownership is structural
 * and cross-tenant binding already 404s, so walking the range never yielded
 * rows — what leaked was the total.
 *
 * The primary keys are untouched: a UUID primary key would rewrite every
 * foreign key, fragment the indexes on the insert-heavy performance tables, and
 * break in-flight queued jobs, which serialise ids.
 */
class PublicUuidRoutingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_url_bound_model_gets_a_uuid_on_insert(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $user = User::factory()->create();

        foreach ([$customer, $campaign, $strategy, $user] as $model) {
            // A real v7, not merely non-null: the column is nullable so the
            // backfill could run, and an empty string would satisfy that.
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $model->uuid,
                class_basename($model).' was created without a usable uuid',
            );
            $this->assertSame($model->uuid, $model->getRouteKey());
        }
    }

    public function test_a_uuid_is_written_even_when_model_events_are_disabled(): void
    {
        // unsetEventDispatcher() is static on Model, so one test calling it for
        // one model disables events for every model for the rest of the
        // process. A `creating` listener silently stopped firing and campaigns
        // came out with a null uuid and an unroutable URL — hence uniqueIds(),
        // which Model::performInsert() applies without a dispatcher.
        $customer = Customer::factory()->create();
        Customer::unsetEventDispatcher();

        try {
            $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

            $this->assertNotSame('', (string) $campaign->uuid, 'the uuid was not written without a dispatcher');
        } finally {
            Customer::setEventDispatcher(app('events'));
        }
    }

    public function test_generated_urls_carry_the_uuid_and_never_the_id(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->assertStringContainsString($customer->uuid, route('customers.edit', $customer));
        $this->assertStringContainsString($campaign->uuid, route('campaigns.show', $campaign));

        $this->assertStringNotContainsString(
            "/customers/{$customer->id}/",
            route('customers.edit', $customer),
            'the URL still counts the customers',
        );
    }

    public function test_a_uuid_url_resolves(): void
    {
        [$user, $customer] = $this->owner();

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('customers.edit', $customer))
            ->assertOk();
    }

    public function test_a_link_minted_before_the_change_still_resolves(): void
    {
        // Binding accepts both forms on purpose. 77 frontend call sites passed
        // a numeric id; a single one missed during the sweep would otherwise
        // take a working page down, and the id was never what protected the row.
        [$user, $customer] = $this->owner();

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get("/customers/{$customer->id}/edit")
            ->assertOk();
    }

    public function test_another_tenants_uuid_is_still_refused(): void
    {
        [$user, $own] = $this->owner();
        $other = Customer::factory()->create();
        $other->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

        /*
           The uuid is not the security boundary and was never asked to be —
           this is the same refusal a numeric id got.

           403 rather than the 404 CLAUDE.md describes, because Customer is the
           tenant rather than a row inside one: CustomerScope does not hide it,
           so binding finds it and the policy refuses. (The controller used to
           redirect "back" with a flash instead, sending an unauthorised request
           to whatever was in the Referer.)
        */
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $own->id])
            ->get(route('customers.edit', $other))
            ->assertForbidden();
    }

    /** @return array{User, Customer} */
    private function owner(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }
}
