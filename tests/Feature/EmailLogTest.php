<?php

namespace Tests\Feature;

use App\Mail\FirstCampaignReady;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\SiteScanCompleted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every email the platform sends leaves a row in email_logs, stamped with
 * the customer it was about, so the admin customer profile can show a
 * per-customer send history. The row is written on MessageSent — i.e. the
 * transport accepted the message, not merely that a job meant to send one.
 */
class EmailLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mailables_are_logged_with_their_customer(): void
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        // sendNow / notifyNow: the base TestCase fakes the queue globally, so
        // queued sends would never reach the mailer (or the MessageSent event).
        Mail::to('client@example.com')->sendNow(new FirstCampaignReady($campaign->fresh('customer'), 'Josh'));

        $log = EmailLog::where('to_email', 'client@example.com')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($customer->id, $log->customer_id);
        $this->assertSame(FirstCampaignReady::class, $log->mailable);
        $this->assertNotNull($log->subject);

        // The stamps are internal plumbing: they must be stripped before
        // transport, never delivered in the recipient's raw message.
        /** @var \Illuminate\Mail\Transport\ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $delivered = $transport->messages()->last()->getOriginalMessage();
        $this->assertFalse($delivered->getHeaders()->has(EmailLog::HEADER_CUSTOMER));
        $this->assertFalse($delivered->getHeaders()->has(EmailLog::HEADER_MAILABLE));
    }

    public function test_notification_mails_are_logged_with_their_customer(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();

        $user->notifyNow(new SiteScanCompleted($customer, 12));

        $log = EmailLog::where('to_email', $user->email)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($customer->id, $log->customer_id);
        $this->assertSame(SiteScanCompleted::class, $log->mailable);
    }

    public function test_admin_customer_page_lists_the_emails(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::unguarded(fn () => \App\Models\Role::firstOrCreate(['name' => 'admin'])));

        $customer = Customer::factory()->create();
        $owner = User::factory()->create();
        $customer->users()->attach($owner->id, ['role' => 'owner']);

        // One row stamped with the customer, one unstamped framework email
        // matched by the owner's address.
        EmailLog::create(['customer_id' => $customer->id, 'to_email' => 'someone@example.com', 'subject' => 'Stamped', 'mailable' => 'App\\Mail\\Whatever']);
        EmailLog::create(['customer_id' => null, 'to_email' => $owner->email, 'subject' => 'Password reset', 'mailable' => null]);
        EmailLog::create(['customer_id' => null, 'to_email' => 'unrelated@example.com', 'subject' => 'Not ours', 'mailable' => null]);

        // The owner also belongs to another customer; that customer's
        // stamped mail to the shared address must NOT leak into this history.
        $other = Customer::factory()->create();
        $other->users()->attach($owner->id, ['role' => 'owner']);
        EmailLog::create(['customer_id' => $other->id, 'to_email' => $owner->email, 'subject' => 'Other customer secret', 'mailable' => 'App\\Mail\\Whatever']);

        $response = $this->actingAs($admin)->get(route('admin.customers.show', $customer));

        $response->assertSuccessful();
        $subjects = collect($response->viewData('page')['props']['emailLogs'] ?? [])->pluck('subject');

        $this->assertTrue($subjects->contains('Stamped'));
        $this->assertTrue($subjects->contains('Password reset'));
        $this->assertFalse($subjects->contains('Not ours'));
        $this->assertFalse($subjects->contains('Other customer secret'));
    }
}
