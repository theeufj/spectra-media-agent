<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    use HasFactory, HasEncryptedAttributes;

    protected $fillable = [
        'user_id',
        'platform',
        'access_token',
        'refresh_token',
        'expires_at',
        'account_id',
        'account_name',
        'scopes',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'scopes'     => 'array',
    ];

    // OAuth tokens are encrypted at rest and never serialized.
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function accessToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    protected function refreshToken(): Attribute
    {
        return $this->encryptedAttribute();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
