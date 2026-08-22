<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply that came back, captured through Resend's inbound webhook.
 *
 * Stored rather than merely forwarded so a reply is visible in the admin
 * portal beside the email that prompted it. A conversation that exists only in
 * three personal inboxes is one nobody else can pick up.
 */
class EmailSequenceReply extends Model
{
    protected $fillable = ['email_sequence_send_id', 'from_email', 'subject', 'body', 'notified_at'];

    protected $casts = ['notified_at' => 'datetime'];

    /** @return BelongsTo<EmailSequenceSend, $this> */
    public function send(): BelongsTo
    {
        return $this->belongsTo(EmailSequenceSend::class, 'email_sequence_send_id');
    }
}
