<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proposal extends Model
{
    const STATUS_GENERATING = 'generating';
    const STATUS_READY = 'ready';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'customer_id',
        'client_name',
        'industry',
        'website_url',
        'budget',
        'goals',
        'platforms',
        'status',
        'proposal_data',
        'pdf_path',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'platforms' => 'array',
        'proposal_data' => 'array',
        'budget' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function markReady(array $data, ?string $pdfPath = null): void
    {
        $this->update([
            'status' => self::STATUS_READY,
            'proposal_data' => $data,
            'pdf_path' => $pdfPath,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }
}
