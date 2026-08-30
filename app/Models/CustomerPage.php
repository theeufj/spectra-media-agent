<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class CustomerPage extends Model
{
    use BelongsToCustomer;
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
        'embedding_model',
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
