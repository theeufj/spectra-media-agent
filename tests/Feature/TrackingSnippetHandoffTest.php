<?php

namespace Tests\Feature;

use App\Mail\TrackingSnippetHandoff;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Installing the tracking snippet must not dead-end an owner-operator.
 *
 * This is the one step on the critical path that a small business owner
 * usually cannot do themselves: it asks them to paste raw JavaScript into
 * their site's <head> and <body>. The page offered no alternative — no
 * developer hand-off, no "we'll do it" — and without the snippet there are no
 * conversions, so the product can never show them what their advertising
 * earned, and the optimisation agents have nothing to optimise toward.
 */
class TrackingSnippetHandoffTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function ownedCustomer(array $attributes = []): array
    {
        Mail::fake();

        $customer = Customer::factory()->create(array_merge([
            'gtm_container_id' => 'GTM-NF47M2K8',
            'website' => 'https://example.com',
        ], $attributes));

        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return [$user, $customer];
    }

    public function test_the_owner_can_send_the_snippet_to_their_web_developer(): void
    {
        [, $customer] = $this->ownedCustomer();

        $this->postJson(route('customers.gtm.handoff', ['customer' => $customer->id]), [
            'email' => 'dev@agency.example',
        ])->assertOk()->assertJsonPath('sent', true);

        Mail::assertQueued(
            TrackingSnippetHandoff::class,
            fn ($mail) => $mail->hasTo('dev@agency.example') && $mail->customer->is($customer)
        );
    }

    public function test_the_email_says_who_asked_for_it(): void
    {
        // It lands with a third party who has no account with us.
        [$user, $customer] = $this->ownedCustomer();

        $this->postJson(route('customers.gtm.handoff', ['customer' => $customer->id]), [
            'email' => 'dev@agency.example',
        ])->assertOk();

        Mail::assertQueued(
            TrackingSnippetHandoff::class,
            fn ($mail) => $mail->requestedBy === $user->name
        );
    }

    public function test_it_refuses_before_a_container_exists(): void
    {
        [, $customer] = $this->ownedCustomer(['gtm_container_id' => null]);

        $this->postJson(route('customers.gtm.handoff', ['customer' => $customer->id]), [
            'email' => 'dev@agency.example',
        ])->assertStatus(422);

        Mail::assertNothingQueued();
    }

    /** @return array<string, array{mixed}> */
    public static function badAddresses(): array
    {
        return [
            'missing' => [null],
            'not an email' => ['the web guy'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAddresses')]
    public function test_an_invalid_address_is_refused(mixed $email): void
    {
        [, $customer] = $this->ownedCustomer();

        $this->postJson(route('customers.gtm.handoff', ['customer' => $customer->id]), ['email' => $email])
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_another_tenants_customer_cannot_be_used_to_send_mail(): void
    {
        // The address is attacker-supplied, so this endpoint must not become a
        // way to send our branded mail on someone else's behalf.
        $this->ownedCustomer();

        $stranger = Customer::factory()->create(['gtm_container_id' => 'GTM-OTHER']);

        /*
         * 403, not the 404 the tenancy convention describes.
         *
         * That rule covers models carrying CustomerScope, where the row simply
         * cannot be found. Customer is the tenant rather than a tenant-owned
         * row, so it is guarded by CustomerPolicy and answers 403 — which is
         * what every other action on this controller already does. Blocking is
         * what matters here; the status is a separate, app-wide question.
         */
        $this->postJson(route('customers.gtm.handoff', ['customer' => $stranger->id]), [
            'email' => 'attacker@example.com',
        ])->assertForbidden();

        Mail::assertNothingQueued();
    }

    public function test_a_guest_cannot_send_it(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['gtm_container_id' => 'GTM-X']);

        $this->postJson(route('customers.gtm.handoff', ['customer' => $customer->id]), [
            'email' => 'dev@agency.example',
        ])->assertUnauthorized();

        Mail::assertNothingQueued();
    }
}
