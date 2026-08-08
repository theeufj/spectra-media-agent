<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Central seam between notification classes and their admin-editable templates.
 *
 * Notifications pass their hardcoded defaults (subject, body, recipient policy) plus the
 * dynamic variables; this service applies any admin override stored in
 * `notification_templates`, renders {{variable}} placeholders, and resolves the recipient
 * user set. When no template row exists, the code defaults pass through unchanged.
 */
class NotificationTemplateService
{
    /**
     * Resolve final copy + delivery for a template key.
     *
     * @param  array{subject?:string,body?:string,recipients?:string}  $defaults  Code fallbacks.
     * @param  array<string,scalar>  $vars  Values for {{placeholder}} substitution.
     * @return array{enabled:bool,subject:string,body:string,recipients:string}
     */
    public function resolve(string $key, array $defaults, array $vars = []): array
    {
        $template = NotificationTemplate::resolve($key);

        $subject = $template?->subject ?: ($defaults['subject'] ?? '');
        $body = $template?->body ?: ($defaults['body'] ?? '');
        $recipients = $template?->recipients ?: ($defaults['recipients'] ?? NotificationTemplate::RECIPIENTS_BOTH);
        $enabled = $template ? $template->enabled : true;

        return [
            'enabled' => $enabled,
            'subject' => $this->render($subject, $vars),
            'body' => $this->render($body, $vars),
            'recipients' => $recipients,
        ];
    }

    /** Substitute {{ placeholder }} tokens with whitelisted variable values. */
    public function render(string $template, array $vars): string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1];

            // Only substitute known variables; leave unknown tokens intact so a typo in a
            // template is visible rather than silently blanking out.
            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $template);
    }

    /**
     * Resolve the actual users to notify for a recipient policy.
     *
     * @param  string  $policy  admins | customers | both
     */
    public function recipientUsers(string $policy, ?Customer $customer = null): Collection
    {
        $admins = fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
        $custUsers = fn () => $customer ? $customer->users()->get() : collect();

        return match ($policy) {
            NotificationTemplate::RECIPIENTS_ADMINS => $admins(),
            NotificationTemplate::RECIPIENTS_CUSTOMERS => $custUsers(),
            default => $admins()->concat($custUsers())->unique('id')->values(),
        };
    }
}
