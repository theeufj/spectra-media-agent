<?php

namespace App\Services\GoogleAds\NegativeKeywords;

use App\Models\NegativeKeywordList;
use Illuminate\Support\Facades\Log;

class AddNegativeKeywordService
{
    public function __invoke(int $campaignId, string $keyword): void
    {
        try {
            $list = NegativeKeywordList::firstOrCreate(
                ['campaign_id' => $campaignId],
                ['name' => 'Default Negative Keyword List']
            );

            $list->keywords()->create([
                'keyword' => $keyword,
            ]);

            Log::info("Added '{$keyword}' to negative keyword list for campaign {$campaignId}.");
        } catch (\Exception $e) {
            Log::error("Error adding negative keyword for campaign {$campaignId}: " . $e->getMessage());
        }
    }
}
