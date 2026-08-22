<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email in a chain.
 *
 * delay_hours is measured from when the person entered the audience, not from
 * the previous step, so disabling or reordering a step cannot silently shift
 * everything after it.
 */
class EmailSequenceStep extends Model
{
    protected $fillable = ['email_sequence_id', 'position', 'delay_hours', 'subject', 'body', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    /** @return BelongsTo<EmailSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(EmailSequence::class, 'email_sequence_id');
    }

    /** @return HasMany<EmailSequenceSend, $this> */
    public function sends(): HasMany
    {
        return $this->hasMany(EmailSequenceSend::class);
    }
}
