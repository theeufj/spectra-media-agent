<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class AgentRun extends Model
{
    use Prunable;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_OP = 'no_op';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job', 'status', 'actions_taken', 'errors', 'warnings',
        'scope', 'duration_ms', 'note', 'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    /** Most recent run per distinct job label. */
    public function scopeLatestPerJob($query)
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')->from('agent_runs')->groupBy('job');
        });
    }

    /**
     * Records eligible for `model:prune` (scheduled nightly).
     *
     * The job dashboard only reports recent runs ("12 runs with no changes"), so
     * anything older is dead weight.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
