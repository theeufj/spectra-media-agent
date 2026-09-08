<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated pitch document for a prospective client.
 *
 * It carries {@see BelongsToCustomer}, which means customer_id has to be set on
 * every row: a NULL never matches the scope's `IN`, so an orphaned proposal is
 * invisible to the user who just generated it, and permanently. That is the
 * invariant ProposalController::store() enforces by refusing to create one when
 * there is no active customer, rather than writing a row nobody can read back.
 */
class Proposal extends Model
{
    use BelongsToCustomer;

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

    public function setWebsiteUrlAttribute(?string $value): void
    {
        $this->attributes['website_url'] = \App\Support\Url::forceHttps($value);
    }

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
