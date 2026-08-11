<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'customer_id',
        'user_id',
        'messages',
    ];

    protected $casts = [
        'messages' => 'array',
    ];

    // Keep last N messages for context window
    const MAX_MESSAGES = 20;

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add a message to the conversation, trimming to MAX_MESSAGES.
     */
    public function addMessage(string $role, string $content): void
    {
        $messages = $this->messages ?? [];

        $messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toIso8601String(),
        ];

        // Keep only the last MAX_MESSAGES
        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }

        $this->update(['messages' => $messages]);
    }

    /**
     * Get or create a conversation for a campaign + user pair.
     */
    /**
     * One thread per campaign, shared by everyone at that customer.
     *
     * Previously keyed on (campaign_id, user_id), so two colleagues discussing
     * the same campaign each got their own thread and neither saw the other's
     * context. The campaign belongs to a customer, not to whoever opened the
     * chat first.
     *
     * $userId is recorded as the most recent participant — that part genuinely
     * is per-person.
     */
    public static function getOrCreate(int $campaignId, int $customerId, ?int $userId = null): static
    {
        $conversation = static::firstOrCreate(
            ['campaign_id' => $campaignId, 'customer_id' => $customerId],
            ['messages' => [], 'user_id' => $userId]
        );

        if ($userId && $conversation->user_id !== $userId) {
            $conversation->update(['user_id' => $userId]);
        }

        return $conversation;
    }
}
