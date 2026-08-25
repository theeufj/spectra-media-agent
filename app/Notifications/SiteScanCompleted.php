<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The website scan promised at onboarding finished successfully.
 *
 * The failure case has had a notification (SiteScanFailed) since the funnel
 * hardening, but success was email-only — and on the qualifying path that
 * email depended on GenerateFirstCampaign surviving, so a crash there meant
 * the customer heard nothing at all. This is the affirmative signal for the
 * bell in every success shape; pass withMail: false when a separate mail
 * (e.g. FirstCampaignReady) already covers the inbox.
 */
class SiteScanCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Customer $customer,
        protected int $totalPages,
        protected ?Campaign $campaign = null,
        protected bool $withMail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withMail ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We\'ve finished scanning '.($this->customer->website ?: 'your website'))
            ->greeting('Hi '.$notifiable->name.',')
            ->line("We read {$this->totalPages} pages of {$this->customer->website} and built your brand profile from them — your voice, your services, your audience.")
            ->line('Take a look and correct anything we got wrong; everything we write for you starts from it.')
            ->action('Review your brand profile', url('/brand-guidelines'))
            ->line('Next step: create your first campaign whenever you\'re ready.')
            ->salutation('— The Site to Spend Team');
    }

    public function toArray(object $notifiable): array
    {
        if ($this->campaign) {
            return [
                'title' => 'Your first campaign is ready to review',
                'message' => 'We scanned your website, built your brand profile, and drafted a first campaign from it. Nothing runs until you confirm the budget.',
                'action_url' => url("/campaigns/{$this->campaign->id}/strategies"),
                'action_text' => 'Review Campaign',
                'customer_id' => $this->customer->id,
                'type' => 'site_scan_completed',
            ];
        }

        return [
            'title' => 'Your website scan is complete',
            'message' => "We read {$this->totalPages} pages and built your brand profile. Review it, then create your first campaign.",
            'action_url' => url('/brand-guidelines'),
            'action_text' => 'Review Brand Profile',
            'customer_id' => $this->customer->id,
            'type' => 'site_scan_completed',
        ];
    }
}
