<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class SummarizeCampaignHistoryService
{
    private $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    public function __invoke(int $campaignId): ?string
    {
        try {
            $campaign = Campaign::findOrFail($campaignId);
            $campaignVersions = $campaign->versions()->orderBy('version_number', 'asc')->get();
            $history = "";

            foreach ($campaignVersions as $version) {
                $history .= "Version {$version->version_number}:\n";
                $history .= json_encode($version->strategy_snapshot, JSON_PRETTY_PRINT) . "\n\n";
            }

            $prompt = "You are a marketing analyst. Summarize the following campaign history into a concise overview that can be used to inform future optimizations.\n\n{$history}";

            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt);

            if (is_null($response) || !isset($response['text'])) {
                Log::error("Failed to summarize campaign history: LLM response was null or missing.");
                return null;
            }

            return $response['text'];
        } catch (\Exception $e) {
            Log::error("Error summarizing campaign history for campaign {$campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return null;
        }
    }
}
