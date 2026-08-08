<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin notification log, editable notification templates, and ad-hoc sends.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class NotificationController extends Controller
{
    public function notificationsIndex()
    {
        return Inertia::render('Admin/Notifications');
    }

    /**
     * Notification template manager — lists the catalog merged with any DB overrides.
     */
    public function notificationTemplatesIndex()
    {
        $catalog = config('notification_templates', []);
        $overrides = NotificationTemplate::whereIn('key', array_keys($catalog))->get()->keyBy('key');

        $templates = collect($catalog)->map(function ($meta, $key) use ($overrides) {
            $row = $overrides->get($key);

            return [
                'key' => $key,
                'category' => $meta['category'] ?? 'General',
                'label' => $meta['label'] ?? $key,
                'description' => $meta['description'] ?? null,
                'default_recipients' => $meta['recipients'] ?? NotificationTemplate::RECIPIENTS_BOTH,
                'variables' => $meta['variables'] ?? [],
                // Current effective override state (null subject/body = "use code default copy").
                'subject' => $row?->subject,
                'body' => $row?->body,
                'recipients' => $row?->recipients ?? ($meta['recipients'] ?? NotificationTemplate::RECIPIENTS_BOTH),
                'enabled' => $row ? $row->enabled : true,
                'customized' => (bool) $row,
            ];
        })->values();

        return Inertia::render('Admin/NotificationTemplates', [
            'templates' => $templates,
            'recipientOptions' => [
                NotificationTemplate::RECIPIENTS_ADMINS,
                NotificationTemplate::RECIPIENTS_CUSTOMERS,
                NotificationTemplate::RECIPIENTS_BOTH,
            ],
        ]);
    }

    /** Save an override row for a known template key. */
    public function updateNotificationTemplate(Request $request)
    {
        $catalog = config('notification_templates', []);

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($catalog))],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'recipients' => ['required', Rule::in([
                NotificationTemplate::RECIPIENTS_ADMINS,
                NotificationTemplate::RECIPIENTS_CUSTOMERS,
                NotificationTemplate::RECIPIENTS_BOTH,
            ])],
            'enabled' => ['required', 'boolean'],
        ]);

        $meta = $catalog[$validated['key']];

        NotificationTemplate::updateOrCreate(
            ['key' => $validated['key']],
            [
                'category' => $meta['category'] ?? 'General',
                'label' => $meta['label'] ?? $validated['key'],
                'description' => $meta['description'] ?? null,
                'channel' => 'mail',
                'subject' => $validated['subject'] ?: null,
                'body' => $validated['body'] ?: null,
                'recipients' => $validated['recipients'],
                'enabled' => $validated['enabled'],
                'variables' => $meta['variables'] ?? [],
            ]
        );

        return back()->with('flash', ['type' => 'success', 'message' => 'Template saved.']);
    }

    /** Render a template's subject/body with sample variables for the live preview. */
    public function previewNotificationTemplate(Request $request, NotificationTemplateService $svc)
    {
        $catalog = config('notification_templates', []);
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($catalog))],
            'subject' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $meta = $catalog[$validated['key']];
        $vars = $meta['variables'] ?? [];

        return response()->json([
            'subject' => $svc->render($validated['subject'] ?: ('✨ '.($vars['title'] ?? $meta['label'])), $vars),
            'body' => $svc->render($validated['body'] ?: ($vars['message'] ?? ''), $vars),
        ]);
    }

    /** Send a rendered test of this template to the current admin. */
    public function sendTestNotificationTemplate(Request $request, NotificationTemplateService $svc)
    {
        $catalog = config('notification_templates', []);
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($catalog))],
            'subject' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $meta = $catalog[$validated['key']];
        $vars = $meta['variables'] ?? [];
        $alertType = str_replace('critical_agent_alert.', '', $validated['key']);

        $notification = new CriticalAgentAlert(
            $alertType,
            $vars['title'] ?? $meta['label'],
            $vars['message'] ?? '',
            ['test_preview' => true]
        );
        $notification->resolvedSubject = $svc->render($validated['subject'] ?: ('✨ '.($vars['title'] ?? $meta['label'])), $vars);
        $notification->resolvedBody = $svc->render($validated['body'] ?: ($vars['message'] ?? ''), $vars);

        $request->user()->notify($notification);

        return back()->with('flash', ['type' => 'success', 'message' => 'Test email sent to '.$request->user()->email]);
    }

    public function sendNotification(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $users = User::all();

        foreach ($users as $user) {
            Mail::to($user->email)->send(new \App\Mail\AdminNotification($user, $request->subject, $request->body));
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Notification sent to all users successfully.',
        ]);
    }
}
