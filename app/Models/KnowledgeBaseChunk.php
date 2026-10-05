<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class KnowledgeBaseChunk extends Model
{
    use BelongsToCustomer, HasNeighbors;

    protected $fillable = ['customer_id', 'knowledge_base_id', 'source_version', 'position', 'content', 'embedding', 'embedding_model'];

    protected $casts = ['embedding' => Vector::class, 'source_version' => 'integer', 'position' => 'integer'];

    protected $hidden = ['embedding'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<KnowledgeBase, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }
}
