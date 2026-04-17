<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeException extends Model
{
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

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }
}
