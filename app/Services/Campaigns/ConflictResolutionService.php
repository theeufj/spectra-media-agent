<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Conflict;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Log;

class ConflictResolutionService
{
    public function __invoke(Recommendation $recommendation, Campaign $campaign): bool
    {
        // Create a record of the conflict
        $conflict = Conflict::create([
            'campaign_id' => $campaign->id,
            'recommendation_id' => $recommendation->id,
            'status' => 'unresolved',
        ]);

        Log::warning("Conflict detected and recorded for campaign {$campaign->id}. Conflict ID: {$conflict->id}", [
            'recommendation' => $recommendation->toArray(),
            'campaign_status' => $campaign->status,
        ]);

        // Depending on the rules, you might notify someone here,
        // or queue a follow-up action.

        return false; // Do not proceed with the recommendation
    }
}
