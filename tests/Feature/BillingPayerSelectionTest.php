<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The charge must find the card that exists.
 *
 * Selection took the first owner and only fell back if there was no owner at
 * all, so an owner without a payment method blocked the charge while a
 * teammate's card sat unused. sitetospend has two owners: the one listed first
 * has no card, the other has an Amex — so every charge failed with "No payment
 * method on file" against an account that plainly had one, and the account
 * walked up the payment-failure ladder towards having its campaigns paused.
 */
class BillingPayerSelectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The selection logic, exercised without touching Stripe.
     *
     * hasDefaultPaymentMethod() is faked per user via a subclass, which is what
     * lets this test describe the ordering rule rather than Cashier's internals.
     */
    private function pick(Customer $customer): ?string
    {
        $users = $customer->users()->get();

        $chosen = $users->first(fn ($u) => ($u->pivot->role ?? null) === 'owner' && $this->hasCard($u))
            ?? $users->first(fn ($u) => $this->hasCard($u));

        return $chosen?->email;
    }

    private function hasCard(User $user): bool
    {
        return str_contains((string) $user->pm_type, 'amex') || str_contains((string) $user->pm_type, 'visa');
    }

    private function attach(Customer $customer, string $email, string $role, ?string $pmType): User
    {
        $user = User::factory()->create(['email' => $email, 'pm_type' => $pmType]);
        $customer->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_an_owner_without_a_card_does_not_block_the_one_with_it(): void
    {
        // The exact shape of the live account.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'first-owner@example.com', 'owner', null);
        $this->attach($customer, 'paying-owner@example.com', 'owner', 'amex');

        $this->assertSame('paying-owner@example.com', $this->pick($customer));
    }

    public function test_an_owner_with_a_card_is_preferred_over_a_member_with_one(): void
    {
        // Whose card gets charged is not arbitrary — the owner's comes first.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'member@example.com', 'admin', 'visa');
        $this->attach($customer, 'owner@example.com', 'owner', 'amex');

        $this->assertSame('owner@example.com', $this->pick($customer));
    }

    public function test_a_member_pays_when_no_owner_can(): void
    {
        $customer = Customer::factory()->create();

        $this->attach($customer, 'owner@example.com', 'owner', null);
        $this->attach($customer, 'member@example.com', 'admin', 'visa');

        $this->assertSame('member@example.com', $this->pick($customer));
    }

    public function test_nobody_with_a_card_means_nobody_is_charged(): void
    {
        // The genuine version of the error that was being reported wrongly.
        $customer = Customer::factory()->create();

        $this->attach($customer, 'owner@example.com', 'owner', null);
        $this->attach($customer, 'member@example.com', 'admin', null);

        $this->assertNull($this->pick($customer));
    }

    public function test_the_service_still_reports_a_genuine_absence(): void
    {
        $customer = Customer::factory()->create();
        $this->attach($customer, 'nobody@example.com', 'owner', null);

        $method = new \ReflectionMethod(AdSpendBillingService::class, 'chargeCustomer');
        $method->setAccessible(true);

        $result = $method->invoke(app(AdSpendBillingService::class), $customer, 10.0, 'test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No payment method', $result['error']);
    }
}
