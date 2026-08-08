<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A captured runtime exception, recorded by the global handler in AppServiceProvider.
 *
 * Named ExceptionLog rather than RuntimeException: as a class in App\Models it would
 * otherwise shadow SPL's \RuntimeException for any unqualified `throw new RuntimeException`
 * written inside this namespace.
 */
class ExceptionLog extends Model
{
    use Prunable;

    protected $table = 'runtime_exceptions';

    protected $fillable = [
        'type',
        'source',
        'file',
        'line',
        'message',
        'trace',
        'url',
        'method',
        'job_class',
        'user_id',
        'customer_id',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Records eligible for `model:prune` (scheduled nightly).
     *
     * Captured exceptions are for triage, not archive. The admin dashboard only
     * ever shows recent ones, and 1,697 had accumulated with nothing pruning them.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
