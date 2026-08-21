<?php

namespace App\Http\Controllers;

use App\Mail\SupportTicketCreated;
use App\Models\Customer;
use App\Models\SupportTicket;
use App\Models\User;
use App\Prompts\SupportChatPrompt;
use App\Services\GeminiService;
use App\Services\Reporting\CrossPlatformAnalyticsService;
use App\Services\Support\SupportChatGuard;
use App\Services\Support\SupportChatTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The in-app support chat widget.
 *
 * One conversation, one ticket. The first message opens it and emails every
 * admin; later messages append to the same ticket's transcript. Per-message
 * tickets would fan an email out to the whole team on every line typed — both
 * unusable and an abuse vector.
 *
 * ORDER MATTERS. The customer's message is written to the ticket BEFORE the
 * model is called. The AI reply is the disposable part; the ticket is what
 * someone is relying on. If Gemini is down, slow, refuses, or the user has hit
 * their daily ceiling, the customer still gets an answer ("the team has this")
 * and support still gets the question. The reverse order would let an AI outage
 * silently swallow support requests, which is the one failure this must not have.
 *
 * Abuse controls live in SupportChatGuard; rate limiting is on the route.
 */
class SupportChatController extends Controller
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly SupportChatGuard $guard,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => [
                'required', 'string', 'min:2',
                'max:'.config('support_chat.max_message_length', 2000),
            ],
            // Continues an existing conversation. Scoped to this user's own
            // chat tickets, so it cannot be used to append to someone else's.
            'ticket_id' => [
                'nullable', 'integer',
                Rule::exists('support_tickets', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('source', 'chatbot'),
            ],
        ]);

        $user = $request->user();
        $message = trim($validated['message']);

        $ticket = isset($validated['ticket_id'])
            ? SupportTicket::where('user_id', $user->id)->find($validated['ticket_id'])
            : null;

        $verdict = $this->guard->check($user, $ticket);

        if (! $verdict['allowed']) {
            // 200, not an error status: this is a normal conversational outcome
            // the widget renders as a reply, not a failure to retry.
            return response()->json([
                'reply' => $verdict['message'],
                'ticket_id' => $ticket?->id,
                'closed' => true,
                'reason' => $verdict['reason'],
            ]);
        }

        $isNew = $ticket === null;
        $ticket = $isNew ? $this->openTicket($user, $message) : $ticket;

        $this->appendToTranscript($ticket, 'customer', $message);

        $reply = $this->replyTo($user, $ticket, $message);

        $this->appendToTranscript($ticket, 'assistant', $reply);

        // Emailed once, when the conversation opens. Follow-up messages land on
        // the same ticket and are visible in the admin queue; re-emailing per
        // message would make the alert worthless.
        if ($isNew) {
            $this->notifyAdmins($ticket);
        }

        return response()->json([
            'reply' => $reply,
            'ticket_id' => $ticket->id,
            'closed' => false,
        ]);
    }

    private function openTicket(User $user, string $message): SupportTicket
    {
        return SupportTicket::create([
            'user_id' => $user->id,
            'customer_id' => session('active_customer_id'),
            'subject' => $this->subjectFrom($message),
            'description' => $message,
            'priority' => $this->priorityFrom($message),
            'category' => $this->categoryFrom($message),
            'source' => 'chatbot',
            'status' => 'open',
            'transcript' => [],
        ]);
    }

    /**
     * Append one turn to the ticket's transcript.
     *
     * Re-read inside the update so two messages sent in quick succession do not
     * overwrite each other's turn.
     */
    private function appendToTranscript(SupportTicket $ticket, string $role, string $text): void
    {
        $ticket->refresh();

        $ticket->update([
            'transcript' => [
                ...($ticket->transcript ?? []),
                ['role' => $role, 'text' => $text, 'at' => now()->toIso8601String()],
            ],
        ]);
    }

    /**
     * Something useful to say while the customer waits for a human.
     *
     * Never throws, and never leaves the customer without a reply. Both the
     * budget ceiling and any model failure fall through to the same holding
     * message — by which point the ticket already exists either way.
     */
    private function replyTo(User $user, SupportTicket $ticket, string $message): string
    {
        if ($this->guard->aiBudgetExhausted($user)) {
            Log::info('Support chat AI budget exhausted; ticket still raised', [
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
            ]);

            return SupportChatPrompt::fallbackReply();
        }

        try {
            $this->guard->recordAiReply($user);

            $customer = $ticket->customer;

            $response = $customer
                ? $this->answerWithTools($customer, $ticket, $message)
                : $this->answerWithoutTools($ticket, $message);

            $text = trim($response['text'] ?? '');

            return $text !== '' ? $text : SupportChatPrompt::fallbackReply();
        } catch (\Throwable $e) {
            report($e);
            Log::error('Support chat AI reply failed; ticket was still raised', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return SupportChatPrompt::fallbackReply();
        }
    }

    /**
     * Answer using read-only tools scoped to this customer's account.
     *
     * The customer is bound into SupportChatTools here, server-side, from the
     * ticket. No tool accepts an account identifier and none is declared to the
     * model, so a customer typing "show me DigitalAF's spend" cannot become a
     * cross-tenant read — the model has no way to express it.
     *
     * maxToolCalls is deliberately low. A support answer needs an overview and
     * maybe a breakdown; a model looping through fifteen calls is stuck, and
     * every call is latency the customer is watching a "Typing…" indicator for.
     *
     * @return array<string, mixed>|null
     */
    private function answerWithTools(Customer $customer, SupportTicket $ticket, string $message): ?array
    {
        $tools = new SupportChatTools($customer, app(CrossPlatformAnalyticsService::class));

        return $this->gemini->generateWithFunctionCalling(
            model: config('ai.models.default'),
            systemInstruction: SupportChatPrompt::systemInstruction(),
            prompt: SupportChatPrompt::generate($message, $customer->name),
            tools: SupportChatTools::declarations(),
            toolHandler: fn (string $name, array $args) => $tools->handle($name, $args),
            config: [
                'temperature' => 0.4,   // support answers should be dull and repeatable
                'maxOutputTokens' => 800,
            ],
            context: ['customer_id' => $customer->id, 'task_type' => 'conversational'],
            maxToolCalls: 4,
        );
    }

    /**
     * Answer without tools, for a user who has no account selected yet.
     *
     * There is nothing to look at, so offering the model tools that can only
     * return "no account" wastes a round trip and invites it to talk about data
     * that does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function answerWithoutTools(SupportTicket $ticket, string $message): ?array
    {
        return $this->gemini->generateContent(
            model: config('ai.models.default'),
            prompt: SupportChatPrompt::generate($message, null),
            config: [
                'temperature' => 0.4,
                'maxOutputTokens' => 600,
            ],
            systemInstruction: SupportChatPrompt::systemInstruction(),
            context: ['customer_id' => null, 'task_type' => 'conversational'],
        );
    }

    /**
     * Email every admin.
     *
     * Guarded per recipient: one bad address must not stop the others being
     * told, and none of it may cost the customer their reply — the ticket is
     * already saved and in the admin queue regardless of what the mailer does.
     */
    private function notifyAdmins(SupportTicket $ticket): void
    {
        $ticket->load(['user', 'customer']);

        foreach (User::admins() as $admin) {
            try {
                Mail::to($admin->email)->queue(new SupportTicketCreated($ticket));
            } catch (\Throwable $e) {
                report($e);
                Log::error('Failed to email an admin about a support ticket', [
                    'ticket_id' => $ticket->id,
                    'admin_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A readable subject for the admin queue.
     *
     * Chat messages have no subject line, and "Support request" repeated fifty
     * times down the queue is unscannable. The first sentence is almost always
     * the actual question.
     */
    private function subjectFrom(string $message): string
    {
        $firstSentence = preg_split('/(?<=[.?!])\s+/', $message, 2)[0] ?? $message;

        return Str::limit(trim($firstSentence) ?: $message, 120);
    }

    /**
     * Route obvious money and access problems to the top of the queue.
     *
     * Keyword matching, deliberately — not the model. Triage decides how fast a
     * human looks at this, so it must not depend on an external service being
     * up, and it must behave the same way every time.
     */
    private function priorityFrom(string $message): string
    {
        return $this->mentions($message, [
            'refund', 'charged', 'double charge', 'overcharge', 'payment failed',
            'card declined', 'locked out', "can't log in", 'cannot log in',
            'urgent', 'asap', 'outage', 'cancel my',
        ]) ? 'high' : 'normal';
    }

    private function categoryFrom(string $message): string
    {
        return match (true) {
            $this->mentions($message, ['bill', 'invoice', 'charge', 'refund', 'payment', 'card', 'price', 'plan', 'subscription']) => 'billing',
            $this->mentions($message, ['campaign', 'budget', 'keyword', 'creative', 'audience', ' ads']) => 'campaign',
            $this->mentions($message, ['error', 'bug', 'broken', 'not working', 'log in', 'login', 'crash']) => 'technical',
            default => 'general',
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        return Str::contains(Str::lower($haystack), $needles);
    }
}
