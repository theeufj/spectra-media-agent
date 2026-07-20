<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_OP     = 'no_op';
    public const STATUS_PARTIAL   = 'partial';
    public const STATUS_FAILED    = 'failed';

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
}
