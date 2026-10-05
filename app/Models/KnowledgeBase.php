<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors; // Corrected trait name
use Pgvector\Laravel\Vector; // Import the Vector class

class KnowledgeBase extends Model
{
    use BelongsToCustomer;
    use HasFactory;
    use HasNeighbors; // Corrected trait name

    /**
     * The attributes that are mass assignable.
     * In Go, you might control this with struct tags (`json:"field"`) or by manually mapping fields.
     * In Laravel, the `$fillable` array is a security feature that prevents mass-assignment vulnerabilities.
     * Only the fields listed here can be set when using methods like `create()` or `updateOrCreate()`.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'customer_id',
        'url',
        'content',
        'css_content',
        'embedding',
        'embedding_model',
        'file_path',
        'source_type',
        'original_filename',
        'title',
        'processing_status',
        'processing_error',
        'excluded_at',
        'fetched_at',
        'indexed_at',
        'content_hash',
        'source_version',
    ];

    /**
     * The attributes that should be cast.
     * This tells Laravel how to handle specific data types.
     * We must cast the 'embedding' column to the package's Vector class.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'embedding' => Vector::class,
        'excluded_at' => 'datetime',
        'fetched_at' => 'datetime',
        'indexed_at' => 'datetime',
        'source_version' => 'integer',
    ];

    protected $hidden = ['embedding', 'css_content', 'file_path'];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<KnowledgeBaseChunk, $this> */
    public function chunks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KnowledgeBaseChunk::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, $this> */
    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
