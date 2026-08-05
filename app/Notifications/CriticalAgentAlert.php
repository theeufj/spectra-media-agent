<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * CriticalAgentAlert
 *
 * Real-time notification for critical campaign events detected by agents (disapprovals,
 * budget exhaustion, conversion drops, spend anomalies, automation-health issues, …).
 *
 * Delivery is admin-configurable: prefer the static {@see deliver()} entrypoint, which
 * resolves per-alertType copy, recipient policy and an on/off switch from the
 * `notification_templates` table (key `critical_agent_alert.{alertType}`) before sending.
 * When no template row exists the code defaults passed by the caller are used, so the
 * feature is safe to roll out incrementally.
 */
class CriticalAgentAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const RECIPIENTS_ADMINS    = NotificationTemplate::RECIPIENTS_ADMINS;
    public const RECIPIENTS_CUSTOMERS = NotificationTemplate::RECIPIENTS_CUSTOMERS;
    public const RECIPIENTS_BOTH      = NotificationTemplate::RECIPIENTS_BOTH;

    public string $alertType;
    public string $title;
    public string $message;
    public array $details;

    /** Copy resolved by deliver() from the admin template (null = use title/message). */
    public ?string $resolvedSubject = null;
    public ?string $resolvedBody = null;

    public function __construct(string $alertType, string $title, string $message, array $details = [])
    {
        $this->alertType = $alertType;
        $this->title = $title;
        $this->message = $message;
        $this->details = $details;

        // Spread concurrent alerts across 30 s to stay within Resend's 5 req/s limit
        $this->delay(now()->addSeconds(rand(0, 30)));
        $this->onQueue('notifications');
    }

    /**
     * Resolve template copy + recipients + on/off, then send to the right users.
     * This is the canonical way to raise a CriticalAgentAlert.
     *
     * @param  string       $defaultRecipients  Code fallback policy when no template row exists:
     *                                           NotificationTemplate::RECIPIENTS_ADMINS|CUSTOMERS|BOTH.
     * @param  ?Customer     $customer           Needed to resolve the 'customers'/'both' audience.
     */
    public static function deliver(
        string $alertType,
        string $title,
        string $message,
        array $details = [],
        string $defaultRecipients = NotificationTemplate::RECIPIENTS_BOTH,
        ?Customer $customer = null
    ): void {
        $svc = app(NotificationTemplateService::class);
        $key = 'critical_agent_alert.' . $alertType;

        // Scalars from details are exposed as {{placeholders}} for admin-authored copy.
        $vars = array_merge(
            array_filter($details, fn ($v) => is_scalar($v)),
            ['title' => $title, 'message' => $message]
        );

        $resolved = $svc->resolve($key, [
            'subject'    => "✨ {$title}",
            'body'       => $message,
            'recipients' => $defaultRecipients,
        ], $vars);

        if (! $resolved['enabled']) {
            return; // admin has switched this alert off
        }

        $recipients = $svc->recipientUsers($resolved['recipients'], $customer);
        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new self($alertType, $title, $message, $details);
        $notification->resolvedSubject = $resolved['subject'];
        $notification->resolvedBody = $resolved['body'];

        NotificationFacade::send($recipients, $notification);
    }

    public function via(object $notifiable): array
    {
        // Admin "send test" previews always deliver — never deduped.
        if (!empty($this->details['test_preview'])) {
            return ['mail'];
        }

        // Deduplicate: same alert type + campaign within 24 hours sends only once per user
        $campaignId = $this->details['campaign_id'] ?? 'global';
        $cacheKey   = "notif:critical:{$this->alertType}:{$campaignId}:{$notifiable->id}";
        if (Cache::has($cacheKey)) {
            return [];
        }
        Cache::put($cacheKey, true, now()->addHours(24));

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->resolvedSubject ?: "✨ {$this->title}")
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($this->resolvedBody ?: $this->message);

        if (!empty($this->details['campaign_name'])) {
            $mail->line("Campaign: {$this->details['campaign_name']}");
        }

        if (!empty($this->details['issues'])) {
            $mail->line("Here is what we fixed:");
            foreach ($this->details['issues'] as $issue) {
                $issueText = is_array($issue) ? ($issue['message'] ?? json_encode($issue)) : $issue;
                $mail->line("- {$issueText}");
            }
        }

        if (!empty($this->details['action_required'])) {
            $mail->line("Action Required: {$this->details['action_required']}");
        } elseif (!empty($this->details['auto_resolved'])) {
            // Only claim we resolved something when the alert genuinely represents a
            // completed auto-fix. Previously this reassurance was the default for ANY
            // alert without an action_required, so admin/health alerts about crashing
            // jobs falsely told the reader everything was handled.
            $mail->line("You do not need to take any action — our agents automatically resolved this for you.");
        }

        if (!empty($this->details['campaign_id'])) {
            $mail->action('View Campaign', url('/campaigns/' . $this->details['campaign_id']));
        }

        return $mail->salutation('— Site to Spend');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'alert_type' => $this->alertType,
            'title' => $this->title,
            'message' => $this->message,
            'details' => $this->details,
            'severity' => $this->details['severity'] ?? 'critical',
        ];
    }
}
