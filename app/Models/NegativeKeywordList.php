<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NegativeKeywordList extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'name',
    ];

    public function keywords()
    {
        return $this->hasMany(NegativeKeyword::class);
    }
}
