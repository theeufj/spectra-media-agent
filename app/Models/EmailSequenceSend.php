<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A record that one person was sent one step.
 *
 * Written before the mail is queued, not after. The unique index on
 * (step, recipient) is what makes a retry, an overlapping schedule run or a
 * double dispatch unable to email somebody the same thing twice — and being
 * emailed twice is precisely the failure that makes a follow-up sequence feel
 * like spam.
 */
class EmailSequenceSend extends Model
{
    protected $fillable = ['email_sequence_step_id', 'recipient_type', 'recipient_id', 'email', 'sent_at', 'failure'];

    protected $casts = ['sent_at' => 'datetime'];

    /** @return BelongsTo<EmailSequenceStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(EmailSequenceStep::class, 'email_sequence_step_id');
    }

    /** @return HasMany<EmailSequenceReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(EmailSequenceReply::class);
    }
}
