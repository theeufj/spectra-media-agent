<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSession extends Model
{
    protected $fillable = [
        'token',
        'email',
        'platform',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'google_ads_customer_id',
        'facebook_ad_account_id',
        'status',
        'audit_results',
        'score',
        'completed_at',
        'converted_at',
        'converted_user_id',
    ];

    protected $casts = [
        'audit_results' => 'array',
        'completed_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'running']);
    }

    public function markConverted(User $user): void
    {
        $this->update([
            'converted_at' => now(),
            'converted_user_id' => $user->id,
        ]);
    }
}
