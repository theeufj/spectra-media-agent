<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The website scan promised at onboarding could not finish.
 *
 * Every terminal dead-end in the crawl → brand-guidelines chain sends this.
 * The customer was told "we're scanning your website now" the moment they
 * signed up; if the chain dies without this notification, that message is the
 * last thing they ever hear from us.
 */
class SiteScanFailed extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\TenantAware;
    use Queueable;

    public function __construct(
        protected Customer $customer,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We couldn't finish scanning ".($this->customer->website ?: 'your website'))
            ->greeting('Hi '.$notifiable->name.',')
            ->line("We ran into a problem while scanning {$this->customer->website} to learn about your business.")
            ->line($this->reason)
            ->line('You can still get set up in a couple of minutes: add a few pages or a description of your business to your knowledge base, and we\'ll build your first campaign from that instead.')
            ->action('Add your content', $this->tenantUrl('/knowledge-base'))
            ->line('If your site was just temporarily unreachable, reply to this email and we\'ll rerun the scan for you.')
            ->salutation($this->teamSalutation());
    }

    public function toArray(object $notifiable): array
    {
        // title/message/action_* are lifted into columns by the custom
        // Notification model's creating hook — they are what the bell shows.
        return [
            'title' => 'We couldn\'t finish scanning your website',
            'message' => $this->reason.' Add your content manually and we\'ll build from that instead.',
            'action_url' => $this->tenantUrl('/knowledge-base'),
            'action_text' => 'Add Content',
            'customer_id' => $this->customer->id,
            'website' => $this->customer->website,
            'reason' => $this->reason,
            'type' => 'site_scan_failed',
        ];
    }
}
