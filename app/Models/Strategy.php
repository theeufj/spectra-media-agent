<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Strategy extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'platform',
        'ad_copy_strategy',
        'imagery_strategy',
        'video_strategy',
        'status',
        'signed_off_at',
    ];

    /**
     * A Strategy belongs to a Campaign.
     * This defines the inverse of the one-to-many relationship.
     * In Go, this might be a pointer back to the parent Campaign struct: `Campaign *Campaign`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
