<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'email',
        'role',
        'token',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
