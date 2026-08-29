<?php

namespace Tests\Feature;

use App\Jobs\VerifyLinkedGoogleAdsAccess;
use App\Mail\LinkAccessLost;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\MaintenanceSummaryNotification;
use App\Services\EarlyExitFeeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

/**
 * Drift protection for bring-your-own-account customers: the day they
 * revoke our manager access (or cancel inside the minimum period after we
 * built in their account), we notice, stand down, tell them what stops,
 * and assess the early-exit terms — exactly once.
 */
class DriftProtectionTest extends TestCase
{
    use DatabaseTransactions;

    private function linkedCustomer(array $attrs = []): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(array_merge([
            'google_ads_link_status' => 'active',
            'google_ads_customer_id' => (string) random_int(1000000000, 9999999999),
            'service_type' => 'managed',
        ], $attrs));
        $customer->users()->attach($user->id, ['role' => 'owner']);
        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => '999888777',
        ]);

        return [$user, $customer];
    }

    private function jobWithProbe(?bool $result): VerifyLinkedGoogleAdsAccess
    {
        return new class($result) extends VerifyLinkedGoogleAdsAccess
        {
            public function __construct(private ?bool $result) {}

            protected function probe(Customer $customer): ?bool
            {
                return $this->result;
            }
        };
    }

    public function test_a_revoked_link_is_detected_and_announced(): void
    {
        Mail::fake();
        [, $customer] = $this->linkedCustomer();

        $this->jobWithProbe(false)->handle();

        $customer->refresh();
        $this->assertSame('revoked', $customer->google_ads_link_status);
        $this->assertNotNull($customer->early_exit_assessed_at);
        Mail::assertSent(LinkAccessLost::class, 1);
    }

    public function test_an_inconclusive_probe_never_reads_as_revocation(): void
    {
        Mail::fake();
        [, $customer] = $this->linkedCustomer();

        $this->jobWithProbe(null)->handle();

        $this->assertSame('active', $customer->fresh()->google_ads_link_status);
        Mail::assertNotSent(LinkAccessLost::class);
    }

    public function test_a_healthy_link_is_left_alone(): void
    {
        Mail::fake();
        [, $customer] = $this->linkedCustomer();

        $this->jobWithProbe(true)->handle();

        $this->assertSame('active', $customer->fresh()->google_ads_link_status);
        Mail::assertNothingSent();
    }

    public function test_early_exit_only_covers_the_drift_case(): void
    {
        $service = new EarlyExitFeeService;

        [, $qualifying] = $this->linkedCustomer();
        $this->assertTrue($service->applies($qualifying));

        // Our own sub-account: they walk away from the build, not with it.
        [, $ourAccount] = $this->linkedCustomer(['google_ads_link_status' => null]);
        $this->assertFalse($service->applies($ourAccount));

        // One-time setup customers already paid for exactly this.
        [, $setupOnly] = $this->linkedCustomer(['service_type' => 'setup_only']);
        $this->assertFalse($service->applies($setupOnly));

        // Outside the minimum period the build is earned.
        [, $veteran] = $this->linkedCustomer();
        $veteran->timestamps = false;
        $veteran->forceFill(['created_at' => now()->subMonths(4)])->save();
        $this->assertFalse($service->applies($veteran));
    }

    public function test_assessment_happens_exactly_once_with_the_full_fee_when_nothing_was_paid(): void
    {
        Mail::fake();
        [, $customer] = $this->linkedCustomer();
        $service = new EarlyExitFeeService;

        $service->assess($customer, 'link_revoked');
        $customer->refresh();
        $this->assertNotNull($customer->early_exit_assessed_at);
        $firstAssessment = $customer->early_exit_assessed_at;

        // Second trigger (e.g. they also cancel) must not re-assess.
        $this->assertFalse($service->applies($customer));
        $service->assess($customer, 'subscription_cancelled');

        $this->assertTrue($customer->fresh()->early_exit_assessed_at->equalTo($firstAssessment));
        $this->assertSame(1, \DB::table('activity_logs')
            ->where('action', 'customer_early_exit_assessed')
            ->count());
    }

    public function test_subscription_cancellation_triggers_the_assessment(): void
    {
        Mail::fake();
        [$user, $customer] = $this->linkedCustomer();
        $user->forceFill(['stripe_id' => 'cus_test_drift'])->save();

        event(new WebhookReceived([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['customer' => 'cus_test_drift']],
        ]));

        $this->assertNotNull($customer->fresh()->early_exit_assessed_at);
    }

    public function test_the_maintenance_receipt_leads_with_the_totals(): void
    {
        [, $customer] = $this->linkedCustomer();
        $user = User::factory()->create();

        $mail = (new MaintenanceSummaryNotification($customer, [
            'Campaign A' => ['total_changes' => 3, 'healed' => 1, 'negatives_added' => 2],
            'Campaign B' => ['total_changes' => 2, 'keywords_added' => 2],
        ], 2))->toMail($user);

        $this->assertSame('5 improvement(s) made across your campaigns today', $mail->subject);
        $html = $mail->render();
        $this->assertStringContainsString('2 wasted-spend term(s) blocked', $html);
        $this->assertStringContainsString('2 new keyword(s) mined from real searches', $html);
    }
}
