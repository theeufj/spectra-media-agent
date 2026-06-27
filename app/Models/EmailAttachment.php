<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmailAttachment extends Model
{
    protected $fillable = [
        'email_message_id',
        'resend_attachment_id',
        'filename',
        'content_type',
        'size',
        'storage_disk',
        'storage_path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function temporaryUrl(int $minutes = 15): string
    {
        return Storage::disk($this->storage_disk)->temporaryUrl(
            $this->storage_path,
            now()->addMinutes($minutes)
        );
    }
}
