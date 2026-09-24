<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCopy extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'strategy_id',
        'persona_id',
        'platform',
        'headlines',
        'descriptions',
        // DeploymentController::toggleCollateral() flips this through update();
        // without it here the toggle was accepted and discarded, and excluded
        // ad copy deployed anyway. ImageCollateral/VideoCollateral already list it.
        'should_deploy',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'headlines' => 'array',
        'descriptions' => 'array',
        'should_deploy' => 'boolean',
    ];

    /**
     * An AdCopy belongs to a Strategy.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
