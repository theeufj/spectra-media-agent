<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpectraConversionEvent extends Model
{
    // Preserve Stripe's UTC instant when writing the timestamptz event time.
    // Laravel's default date format drops the offset before Postgres sees it.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'event',
        'user_id',
        'gclid',
        'fbclid',
        'mode',
        'value',
        'currency',
        'uploaded_to_google',
        'deduplication_key',
        'ad_identifiers',
        'occurred_at',
        'google_request_id',
        'upload_error',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'uploaded_to_google' => 'boolean',
        'ad_identifiers' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $event, ?int $userId, array $meta = []): self
    {
        $config = config("conversions.events.{$event}", []);

        return static::create([
            'event' => $event,
            'user_id' => $userId,
            'gclid' => $meta['gclid'] ?? null,
            'fbclid' => $meta['fbclid'] ?? null,
            'mode' => $meta['mode'] ?? $config['mode'] ?? 'client',
            'value' => $config['value'] ?? null,
            'currency' => $config['currency'] ?? 'USD',
            'uploaded_to_google' => $meta['uploaded'] ?? false,
        ]);
    }
}
