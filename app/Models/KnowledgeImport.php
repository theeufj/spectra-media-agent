<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;

class KnowledgeImport extends Model
{
    use BelongsToCustomer;

    protected $fillable = ['customer_id', 'user_id', 'website_url', 'status', 'error', 'candidates', 'selected_urls'];

    protected $casts = ['candidates' => 'array', 'selected_urls' => 'array'];
}
