<?php

namespace App\Http\Controllers;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\VideoCollateral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CollateralApprovalController extends Controller
{
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
