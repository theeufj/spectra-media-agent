<?php

namespace App\Http\Controllers;

use App\Models\EmailInbox;
use App\Models\EmailMessage;
use App\Models\EmailAttachment;
use App\Services\EmailInboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EmailInboxController extends Controller
{
    public function __construct(private EmailInboxService $inboxService) {}

    public function index(Request $request): Response
    {
        $inbox = EmailInbox::where('user_id', Auth::id())->firstOrFail();

        $folder = $request->get('folder', 'inbox');
        $threadId = $request->get('thread');

        $query = EmailMessage::where('inbox_id', $inbox->id)
            ->with('attachments')
            ->orderByDesc('created_at');

        if ($folder === 'inbox') {
            $query->where('direction', 'inbound');
        } elseif ($folder === 'sent') {
            $query->where('direction', 'outbound');
        }

        // Collapse to threads: one row per thread_id, latest message first
        $threadIds = (clone $query)
            ->select('thread_id')
            ->groupBy('thread_id')
            ->pluck('thread_id');

        $threads = $threadIds->map(function (string $threadId) use ($inbox) {
            $messages = EmailMessage::where('inbox_id', $inbox->id)
                ->where('thread_id', $threadId)
                ->with('attachments')
                ->orderBy('created_at')
                ->get();

            $latest = $messages->last();
            $unread = $messages->where('direction', 'inbound')->whereNull('read_at')->count();

            return [
                'thread_id' => $threadId,
                'subject' => $latest->subject,
                'snippet' => $this->snippet($latest),
                'from' => $latest->from_address,
                'date' => $latest->created_at->toISOString(),
                'unread' => $unread,
                'message_count' => $messages->count(),
            ];
        })->sortByDesc('date')->values();

        $openThread = null;
        if ($threadId) {
            $threadMessages = EmailMessage::where('inbox_id', $inbox->id)
                ->where('thread_id', $threadId)
                ->with('attachments')
                ->orderBy('created_at')
                ->get()
                ->map(fn($m) => $this->formatMessage($m));

            // Mark inbound messages in the thread as read
            EmailMessage::where('inbox_id', $inbox->id)
                ->where('thread_id', $threadId)
                ->where('direction', 'inbound')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $openThread = [
                'thread_id' => $threadId,
                'subject' => $threadMessages->first()['subject'] ?? '',
                'messages' => $threadMessages,
            ];
        }

        return Inertia::render('Inbox/Index', [
            'inbox' => [
                'id' => $inbox->id,
                'email_address' => $inbox->email_address,
                'display_name' => $inbox->display_name,
                'unread_count' => $inbox->unreadCount(),
            ],
            'threads' => $threads,
            'open_thread' => $openThread,
            'folder' => $folder,
        ]);
    }

    public function send(Request $request)
    {
        $request->validate([
            'to' => 'required|string',
            'subject' => 'required|string|max:998',
            'html' => 'nullable|string',
            'text' => 'nullable|string',
            'cc' => 'nullable|string',
            'bcc' => 'nullable|string',
            'thread_id' => 'nullable|string',
            'in_reply_to' => 'nullable|string',
            'references' => 'nullable|string',
            'attachments.*' => 'nullable|file|max:25600',
        ]);

        $inbox = EmailInbox::where('user_id', Auth::id())->firstOrFail();

        $params = [
            'to' => array_map('trim', explode(',', $request->input('to'))),
            'subject' => $request->input('subject'),
            'html' => $request->input('html'),
            'text' => $request->input('text'),
            'cc' => $request->input('cc') ? array_map('trim', explode(',', $request->input('cc'))) : null,
            'bcc' => $request->input('bcc') ? array_map('trim', explode(',', $request->input('bcc'))) : null,
            'thread_id' => $request->input('thread_id'),
            'in_reply_to' => $request->input('in_reply_to'),
            'references' => $request->input('references'),
        ];

        $files = $request->file('attachments') ?? [];

        $message = $this->inboxService->sendEmail($inbox, $params, $files);

        return back()->with('success', 'Email sent.');
    }

    public function attachment(int $id)
    {
        $attachment = EmailAttachment::whereHas('message', function ($q) {
            $q->whereHas('inbox', fn($q2) => $q2->where('user_id', Auth::id()));
        })->findOrFail($id);

        if (! $attachment->storage_path) {
            abort(404);
        }

        return redirect($attachment->temporaryUrl(30));
    }

    private function formatMessage(EmailMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'from' => $message->from_address,
            'to' => $message->to_addresses,
            'cc' => $message->cc_addresses,
            'subject' => $message->subject,
            'html_body' => $message->html_body,
            'text_body' => $message->text_body,
            'message_id' => $message->message_id,
            'thread_id' => $message->thread_id,
            'read_at' => $message->read_at?->toISOString(),
            'sent_at' => $message->sent_at?->toISOString(),
            'created_at' => $message->created_at->toISOString(),
            'attachments' => $message->attachments->map(fn($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'content_type' => $a->content_type,
                'size' => $a->size,
            ]),
        ];
    }

    private function snippet(EmailMessage $message): string
    {
        $text = $message->text_body
            ?? strip_tags($message->html_body ?? '')
            ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 120);
    }
}
