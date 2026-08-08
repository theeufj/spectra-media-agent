<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
}
