<?php

namespace App\Models;

use App\Enums\ProductFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (customer, user, feature, action, day), incremented rather than
 * appended. See the create migration for why this is a counter and not an
 * event log.
 *
 * Written through App\Services\FeatureUsage\FeatureRecorder, which upserts via
 * the query builder — reads happen here, writes do not.
 */
class FeatureUsageDaily extends Model
{
    use Prunable;

    protected $table = 'feature_usage_daily';

    protected $fillable = [
        'customer_id',
        'user_id',
        'feature',
        'action',
        'day',
        'count',
        'last_at',
    ];

    protected $casts = [
        'day' => 'date',
        'last_at' => 'datetime',
        'count' => 'integer',
        'feature' => ProductFeature::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The first day this table recorded anything.
     *
     * Every panel built on this data must be labelled "since <this date>" and
     * must not render zeros for earlier periods. A chart showing 0% adoption
     * last quarter because the table did not exist then is not a chart with a
     * gap — it is a chart that is wrong.
     */
    public static function recordingSince(): ?string
    {
        return static::min('day');
    }

    /**
     * Records eligible for `model:prune` (scheduled nightly).
     *
     * 400 days rather than a round year so a year-over-year comparison still
     * has both ends. Pruning is configured from day one on purpose: ai_costs
     * is unpruned by policy and has grown without a ceiling, which is a warning
     * worth heeding even though this table is orders of magnitude smaller —
     * one row per user-feature-day, not one per API call.
     */
    public function prunable(): Builder
    {
        return static::where('day', '<', now()->subDays(config('feature_usage.retention_days', 400))->toDateString());
    }
}
