<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMessage extends Model
{
    protected $fillable = [
        'inbox_id',
        'resend_email_id',
        'direction',
        'from_address',
        'to_addresses',
        'cc_addresses',
        'bcc_addresses',
        'subject',
        'html_body',
        'text_body',
        'message_id',
        'thread_id',
        'in_reply_to',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'to_addresses' => 'array',
        'cc_addresses' => 'array',
        'bcc_addresses' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(EmailInbox::class, 'inbox_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
