<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class CustomerPage extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'customer_id',
        'url',
        'title',
        'meta_description',
        'page_type',
        'metadata',
        'content',
        'embedding',
    ];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => Vector::class,
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
