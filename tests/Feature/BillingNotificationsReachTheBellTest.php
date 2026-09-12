<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Money events have to show up inside the product, not only by email.
 *
 * Payment failed, budgets halved, campaigns paused, credit low, ads resumed —
 * every one of these was a bare Mail::to() send. That bypasses the notification
 * system entirely, so none of them could ever reach the bell. A customer whose
 * campaigns were paused for a declined card saw nothing at all in the one place
 * they would look to find out why their ads had stopped.
 *
 * These are the highest-stakes messages the product sends: they are about the
 * customer's money and about whether their advertising is running.
 */
class BillingNotificationsReachTheBellTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{Customer, User, AdSpendCredit} */
    private function customerWithCredit(int $failedCount = 0): array
    {
        Mail::fake();

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'current_balance' => 10.00,
            'daily_budget' => 45.00,
            'failed_charge_count' => $failedCount,
            'status' => 'active',
        ]);

        return [$customer, $user, $credit];
    }

    private function handleFailure(Customer $customer, AdSpendCredit $credit): void
    {
        $m = new \ReflectionMethod(AdSpendBillingService::class, 'handlePaymentFailure');
        $m->setAccessible(true);
        $m->invoke(app(AdSpendBillingService::class), $customer, $credit, 'Your card was declined.');
    }

    /** @return array<string, array{int, string}> */
    public static function failureStages(): array
    {
        return [
            'first decline enters grace' => [0, 'could not take your ad spend payment'],
            'second decline halves budgets' => [1, 'budgets have been halved'],
            'third decline pauses ads' => [2, 'ads have been paused'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureStages')]
    public function test_each_payment_failure_stage_reaches_the_bell(int $failedCount, string $expected): void
    {
        [$customer, $user, $credit] = $this->customerWithCredit($failedCount);

        $this->handleFailure($customer, $credit);

        $row = Notification::where('user_id', $user->id)->latest()->first();

        $this->assertNotNull($row, 'A billing failure must leave an in-app record.');
        $this->assertStringContainsString($expected, strtolower($row->title.' '.$row->message));
        $this->assertSame(Notification::TYPE_BILLING_WARNING, $row->type);
        $this->assertNotNull($row->action_url);
    }

    public function test_the_bell_entry_is_never_a_blank_notification(): void
    {
        // The failure mode this whole exercise started from.
        [$customer, $user, $credit] = $this->customerWithCredit();

        $this->handleFailure($customer, $credit);

        $row = Notification::where('user_id', $user->id)->latest()->firstOrFail();

        $this->assertNotSame('Notification', $row->title);
        $this->assertNotSame('', $row->message);
    }

    public function test_it_tells_them_in_words_rather_than_status_codes(): void
    {
        [$customer, $user, $credit] = $this->customerWithCredit(2);

        $this->handleFailure($customer, $credit);

        $row = Notification::where('user_id', $user->id)->latest()->firstOrFail();

        // No internal vocabulary in front of a customer.
        foreach (['PAYMENT_PAUSED', 'failed_charge_count', 'grace_period', 'null'] as $internal) {
            $this->assertStringNotContainsString($internal, $row->title.$row->message);
        }
    }

    public function test_a_notification_failure_does_not_abort_the_billing_run(): void
    {
        // The run has already moved money by this point; a bell entry that
        // cannot be written must not throw on top of that.
        [$customer, , $credit] = $this->customerWithCredit();
        $customer->users()->detach();

        $this->handleFailure($customer->fresh(), $credit);

        // It completed, and wrote nothing rather than half of something.
        $this->assertSame(0, Notification::where('customer_id', $customer->id)->count());
        $this->assertSame(1, $credit->fresh()->failed_charge_count);
    }
}
