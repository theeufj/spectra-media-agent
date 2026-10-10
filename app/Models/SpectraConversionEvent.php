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
        'google_conversion_resource',
        'google_accepted_at',
        'google_processing_status',
        'google_processing_checked_at',
        'google_processing_attempts',
        'google_processing_details',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'uploaded_to_google' => 'boolean',
        'ad_identifiers' => 'array',
        'occurred_at' => 'immutable_datetime',
        'google_accepted_at' => 'immutable_datetime',
        'google_processing_checked_at' => 'immutable_datetime',
        'google_processing_attempts' => 'integer',
        'google_processing_details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasGoogleClickIdentifier(): bool
    {
        return (bool) ($this->ad_identifiers || $this->gclid);
    }

    /** A provider receipt proves acceptance for processing, never ad attribution. */
    public function googleDeliveryStatus(): string
    {
        if ($this->mode !== 'server_google') {
            return $this->mode === 'client' ? 'browser_recorded' : 'other_platform';
        }
        if ($this->uploaded_to_google) {
            if (! $this->google_request_id || ! $this->occurred_at || ! $this->ad_identifiers || ! $this->google_accepted_at) {
                return 'legacy_unverified';
            }

            return match ($this->google_processing_status) {
                'SUCCESS' => empty($this->google_processing_details['warnings']) ? 'processed' : 'processed_with_warnings',
                'FAILED', 'PARTIAL_SUCCESS' => 'processing_failed',
                'PROCESSING' => 'processing',
                'TIMED_OUT', 'CHECK_FAILED' => 'processing_unknown',
                default => 'provider_accepted',
            };
        }
        if ($this->upload_error) {
            return 'upload_failed';
        }

        return $this->hasGoogleClickIdentifier() ? 'awaiting_upload' : 'no_click_identifier';
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
