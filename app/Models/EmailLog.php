<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound email, recorded at the moment the transport accepted it.
 * Written by App\Listeners\LogSentEmail; surfaced on the admin customer
 * profile as a per-customer send history.
 */
class EmailLog extends Model
{
    protected $fillable = [
        'customer_id',
        'to_email',
        'subject',
        'mailable',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
