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
    public static function getOrCreate(int $campaignId, int $userId): static
    {
        return static::firstOrCreate(
            ['campaign_id' => $campaignId, 'user_id' => $userId],
            ['messages' => []]
        );
    }
}
