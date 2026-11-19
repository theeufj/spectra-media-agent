<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Jobs\RefreshApprovedPerformanceMaxCreative;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CollateralApprovalController extends Controller
{
    public function release(Request $request, Campaign $campaign, Strategy $strategy, CreativeRelease $release): RedirectResponse
    {
        $this->authorize('update', $campaign);
        abort_unless($strategy->campaign_id === $campaign->id, 404);
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        DB::transaction(function () use ($campaign, $strategy, $release, $validated) {
            $current = $campaign->newQuery()->lockForUpdate()->findOrFail($campaign->id);
            $selected = $current->strategies()->lockForUpdate()->findOrFail($strategy->id);
            abort_unless($current->status === CampaignStatus::Active && $selected->campaign_type === 'performance_max'
                && $selected->deployment_status === 'active', 422, 'This campaign is not available for a live creative update.');
            $release->approve($selected, $validated['fingerprint']);
            RefreshApprovedPerformanceMaxCreative::dispatch($selected->id, $validated['fingerprint'])->afterCommit();
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Your approved ads are queued for an update in Google Ads.']);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $validated = $request->validate([
            'type' => ['required', Rule::in(['ad_copy', 'image', 'video'])],
            'ids' => ['required', 'array', 'min:1', 'max:30'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'field' => ['required', Rule::in(['should_deploy', 'is_seed'])],
            'value' => ['required', 'boolean'],
        ]);
        abort_if($validated['field'] === 'is_seed' && $validated['type'] !== 'image', 422, 'Only images can be AI references.');
        $model = match ($validated['type']) {
            'ad_copy' => AdCopy::class,
            'image' => ImageCollateral::class,
            'video' => VideoCollateral::class,
            default => abort(422, 'Choose a supported asset type.'),
        };

        // Approve every size together. A missing or foreign row rolls the whole
        // request back, rather than leaving a concept partly selected.
        $rows = DB::transaction(function () use ($model, $campaign, $validated) {
            $rows = $model::query()->whereIn('id', $validated['ids'])
                ->where(function ($query) use ($campaign, $validated) {
                    if ($validated['type'] !== 'ad_copy') {
                        $query->where('campaign_id', $campaign->id)->orWhereHas('strategy', fn ($q) => $q->where('campaign_id', $campaign->id));
                    } else {
                        $query->whereHas('strategy', fn ($q) => $q->where('campaign_id', $campaign->id));
                    }
                })->orderBy('id')->lockForUpdate()->get();
            abort_unless($rows->count() === count($validated['ids']), 404);
            foreach ($rows as $row) {
                $this->authorize('update', $row);
            }
            foreach ($rows as $row) {
                $row->update([$validated['field'] => $validated['value']]);
            }

            return $rows->map(fn ($row) => ['id' => $row->id, $validated['field'] => (bool) $row->{$validated['field']}]);
        });

        return response()->json(['type' => $validated['type'], 'field' => $validated['field'], 'rows' => $rows]);
    }
}
