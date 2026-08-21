<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The abuse harness for the support chat endpoint.
 *
 * Authentication alone is not a control here. A logged-in customer — or far
 * more likely a retrying frontend stuck in a loop — can still call this
 * endpoint repeatedly, and every call spends money on a Gemini request and
 * fans an email out to every admin. Route throttling caps the rate; this caps
 * the total.
 *
 * The important distinction, and the reason this is a separate class rather
 * than a pile of ifs in the controller: exceeding a limit must NOT lose the
 * customer's message. Every check here either refuses the request outright
 * (a closed feature, a banned user, an absurd conversation) or degrades the AI
 * reply while still recording the ticket. Support getting a plainer answer is
 * acceptable; support never hearing the question is not.
 */
class SupportChatGuard
{
    /** Reason codes returned to the controller. */
    public const OK = 'ok';

    public const DISABLED = 'disabled';

    public const BANNED = 'banned';

    public const CONVERSATION_FULL = 'conversation_full';

    public const TOO_MANY_TICKETS = 'too_many_tickets';

    /**
     * May this request proceed at all?
     *
     * @return array{allowed: bool, reason: string, message: ?string}
     */
    public function check(User $user, ?SupportTicket $ticket): array
    {
        if (! config('support_chat.enabled', true)) {
            return $this->deny(self::DISABLED, 'Support chat is unavailable right now. Please email the team.');
        }

        if ($user->banned_at !== null) {
            return $this->deny(self::BANNED, 'Support chat is not available on this account.');
        }

        // Transcript holds both sides, so the cap is doubled to count turns.
        if ($ticket && count($ticket->transcript ?? []) >= config('support_chat.max_messages_per_conversation', 20) * 2) {
            return $this->deny(
                self::CONVERSATION_FULL,
                'This conversation has got long — the team has it and will follow up by email.',
            );
        }

        // Only counts NEW conversations against the cap; continuing an existing
        // one is always allowed, or someone at the limit could never reply to
        // their own open ticket.
        if (! $ticket && $this->openChatTickets($user) >= config('support_chat.max_open_tickets_per_user', 5)) {
            return $this->deny(
                self::TOO_MANY_TICKETS,
                'You already have several open support conversations. The team is working through them.',
            );
        }

        return ['allowed' => true, 'reason' => self::OK, 'message' => null];
    }

    /**
     * Has this user used up today's AI replies?
     *
     * Checked separately from check() because the consequence is different: the
     * message is still recorded and support is still notified, the customer
     * just gets the standard holding reply instead of a generated one.
     */
    public function aiBudgetExhausted(User $user): bool
    {
        return $this->aiRepliesToday($user) >= config('support_chat.max_ai_replies_per_day', 30);
    }

    /**
     * Count an AI reply against today's budget.
     *
     * Keyed per user per calendar day and expired at day end, so the counter
     * cannot leak between users or accumulate forever.
     */
    public function recordAiReply(User $user): void
    {
        $key = $this->budgetKey($user);

        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);
    }

    public function aiRepliesToday(User $user): int
    {
        return (int) Cache::get($this->budgetKey($user), 0);
    }

    private function budgetKey(User $user): string
    {
        return sprintf('support_chat:ai:%d:%s', $user->id, now()->toDateString());
    }

    private function openChatTickets(User $user): int
    {
        return SupportTicket::where('user_id', $user->id)
            ->where('source', 'chatbot')
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
    }

    /**
     * @return array{allowed: bool, reason: string, message: string}
     */
    private function deny(string $reason, string $message): array
    {
        return ['allowed' => false, 'reason' => $reason, 'message' => $message];
    }
}
