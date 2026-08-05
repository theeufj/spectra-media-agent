<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable copy + recipient policy for a notification email.
 *
 * A row OVERRIDES the hardcoded defaults baked into the notification classes; when no
 * row exists for a key, the code falls back to its built-in copy/recipients. This makes
 * the feature safe to roll out incrementally — behaviour only changes once an admin
 * edits a template. Resolution is cached per key.
 */
class NotificationTemplate extends Model
{
    public const RECIPIENTS_ADMINS    = 'admins';
    public const RECIPIENTS_CUSTOMERS = 'customers';
    public const RECIPIENTS_BOTH      = 'both';

    protected $fillable = [
        'key', 'category', 'label', 'description', 'channel',
        'subject', 'body', 'recipients', 'enabled', 'variables',
    ];

    protected $casts = [
        'enabled'   => 'boolean',
        'variables' => 'array',
    ];

    /** Resolve a template by key, cached. Returns null when none is configured. */
    public static function resolve(string $key): ?self
    {
        return Cache::remember("notif_template_{$key}", 3600, fn () => static::where('key', $key)->first());
    }

    public static function clearCache(string $key): void
    {
        Cache::forget("notif_template_{$key}");
    }

    protected static function booted(): void
    {
        static::saved(fn (self $t) => static::clearCache($t->key));
        static::deleted(fn (self $t) => static::clearCache($t->key));
    }
}
