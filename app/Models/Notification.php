<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'customer_id',
        'type',
        'title',
        'message',
        'icon',
        'action_url',
        'action_text',
        'data',
        'read_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Notification types constants.
     */
    const TYPE_STRATEGY_READY = 'campaign.strategy_ready';
    const TYPE_COLLATERAL_READY = 'campaign.collateral_ready';
    const TYPE_DEPLOYMENT_STARTED = 'deployment.started';
    const TYPE_DEPLOYMENT_COMPLETED = 'deployment.completed';
    const TYPE_DEPLOYMENT_FAILED = 'deployment.failed';
    const TYPE_HEALTH_WARNING = 'health.warning';
    const TYPE_HEALTH_CRITICAL = 'health.critical';
    const TYPE_SYSTEM_INFO = 'system.info';
    const TYPE_BILLING_WARNING = 'billing.warning';
    const TYPE_BILLING_SUCCESS = 'billing.success';
    const TYPE_AB_TEST_COMPLETE = 'ab_test.complete';

    /**
     * Get the user that owns the notification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer associated with the notification.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope a query to only include unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope a query to only include read notifications.
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope a query to only include recent notifications.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Mark the notification as read.
     */
    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    /**
     * Mark the notification as unread.
     */
    public function markAsUnread(): void
    {
        $this->update(['read_at' => null]);
    }

    /**
     * Check if the notification has been read.
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Get the icon for the notification type.
     */
    public function getIconAttribute($value): string
    {
        if ($value) {
            return $value;
        }

        return match ($this->type) {
            self::TYPE_STRATEGY_READY => '📋',
            self::TYPE_COLLATERAL_READY => '🎨',
            self::TYPE_DEPLOYMENT_STARTED => '🚀',
            self::TYPE_DEPLOYMENT_COMPLETED => '✅',
            self::TYPE_DEPLOYMENT_FAILED => '❌',
            self::TYPE_HEALTH_WARNING => '⚠️',
            self::TYPE_HEALTH_CRITICAL => '🔴',
            self::TYPE_BILLING_WARNING => '💳',
            self::TYPE_BILLING_SUCCESS => '💰',
            self::TYPE_AB_TEST_COMPLETE => '🏆',
            self::TYPE_SYSTEM_INFO => 'ℹ️',
            default => '📬',
        };
    }

    /**
     * Create a notification for a user.
     */
    public static function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?Customer $customer = null,
        ?array $data = null
    ): static {
        return static::create([
            'user_id' => $user->id,
            'customer_id' => $customer?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'action_text' => $actionText,
            'data' => $data,
        ]);
    }
}
