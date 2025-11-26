<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    /**
     * Get the user who performed the activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subject of the activity (polymorphic).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Log an activity.
     */
    public static function log(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $properties = []
    ): self {
        $user = auth()->user();
        $request = request();

        // Check if admin is impersonating - log the real admin
        $realAdminId = session('impersonate_admin_id');
        if ($realAdminId) {
            $properties['impersonating_user_id'] = $user?->id;
            $properties['impersonating_user_email'] = $user?->email;
            $realAdmin = User::find($realAdminId);
            $user = $realAdmin;
        }

        return self::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Get a human-readable action label.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'login' => 'Logged in',
            'logout' => 'Logged out',
            'impersonate_start' => 'Started impersonating user',
            'impersonate_stop' => 'Stopped impersonating user',
            'user_created' => 'User created',
            'user_updated' => 'User updated',
            'user_banned' => 'User banned',
            'user_unbanned' => 'User unbanned',
            'user_promoted' => 'User promoted to admin',
            'campaign_created' => 'Campaign created',
            'campaign_updated' => 'Campaign updated',
            'campaign_deleted' => 'Campaign deleted',
            'campaign_paused' => 'Campaign paused',
            'campaign_started' => 'Campaign started',
            'customer_created' => 'Customer created',
            'customer_deleted' => 'Customer deleted',
            'subscription_created' => 'Subscription created',
            'subscription_cancelled' => 'Subscription cancelled',
            'settings_updated' => 'Settings updated',
            'password_reset' => 'Password reset',
            'email_verified' => 'Email verified',
            default => ucwords(str_replace('_', ' ', $this->action)),
        };
    }

    /**
     * Get the icon for this action type.
     */
    public function getActionIconAttribute(): string
    {
        return match ($this->action) {
            'login', 'logout' => 'key',
            'impersonate_start', 'impersonate_stop' => 'user-circle',
            'user_created', 'user_updated', 'user_banned', 'user_unbanned', 'user_promoted' => 'user',
            'campaign_created', 'campaign_updated', 'campaign_deleted', 'campaign_paused', 'campaign_started' => 'megaphone',
            'customer_created', 'customer_deleted' => 'building',
            'subscription_created', 'subscription_cancelled' => 'credit-card',
            'settings_updated' => 'cog',
            default => 'information-circle',
        };
    }

    /**
     * Get the color for this action type.
     */
    public function getActionColorAttribute(): string
    {
        return match ($this->action) {
            'login', 'email_verified' => 'green',
            'logout' => 'gray',
            'impersonate_start', 'impersonate_stop' => 'yellow',
            'user_banned', 'campaign_deleted', 'customer_deleted', 'subscription_cancelled' => 'red',
            'user_unbanned', 'user_promoted', 'campaign_started' => 'green',
            'campaign_paused' => 'orange',
            default => 'blue',
        };
    }
}
