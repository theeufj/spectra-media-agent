<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpectraConversionEvent extends Model
{
    protected $fillable = [
        'event',
        'user_id',
        'gclid',
        'fbclid',
        'mode',
        'value',
        'currency',
        'uploaded_to_google',
    ];

    protected $casts = [
        'value'              => 'decimal:2',
        'uploaded_to_google' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $event, ?int $userId, array $meta = []): self
    {
        $config = config("conversions.events.{$event}", []);
        return static::create([
            'event'              => $event,
            'user_id'            => $userId,
            'gclid'              => $meta['gclid'] ?? null,
            'fbclid'             => $meta['fbclid'] ?? null,
            'mode'               => $meta['mode'] ?? $config['mode'] ?? 'client',
            'value'              => $config['value'] ?? null,
            'currency'           => $config['currency'] ?? 'USD',
            'uploaded_to_google' => $meta['uploaded'] ?? false,
        ]);
    }
}
