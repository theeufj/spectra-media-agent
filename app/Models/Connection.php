<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    use HasFactory;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
