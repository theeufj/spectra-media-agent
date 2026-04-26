<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Competitor;
use App\Jobs\CrawlCompetitorWebsite;
use App\Services\Agents\CompetitorAnalysisAgent;
use App\Services\CompetitorGapAnalysisService;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analyze a single competitor URL for the War Room.
 * After analysis completes, regenerates the gap analysis for all pinned competitors.
 */
class AnalyzeWarRoomCompetitor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 300;

    public function __construct(
        protected Customer $customer,
        protected Competitor $competitor,
    ) {}

    public function handle(CompetitorAnalysisAgent $analysisAgent, CompetitorGapAnalysisService $gapService): void
    {
        Log::info('AnalyzeWarRoomCompetitor: Starting', [
            'customer_id' => $this->customer->id,
            'competitor_id' => $this->competitor->id,
            'url' => $this->competitor->url,
        ]);

        // Step 1: Analyze the competitor
        $result = $analysisAgent->analyze($this->competitor, $this->customer);

        if (!$result['success']) {
            Log::warning('AnalyzeWarRoomCompetitor: Analysis failed', [
                'competitor_id' => $this->competitor->id,
                'error' => $result['error'],
            ]);
            return;
        }

        // Step 2: Crawl the competitor's website to populate the knowledge base
        if ($this->competitor->url) {
            $user = $this->customer->users()->first();
            if ($user) {
                CrawlCompetitorWebsite::dispatch($user, $this->competitor->url, $this->customer->id);
            }
        }

        // Step 3: Regenerate gap analysis for all pinned War Room competitors
        $pinnedIds = $this->customer->war_room_competitors ?? [];

        if (!empty($pinnedIds)) {
            $competitors = Competitor::whereIn('id', $pinnedIds)
                ->where('customer_id', $this->customer->id)
                ->whereNotNull('messaging_analysis')
                ->get();

            if ($competitors->isNotEmpty()) {
                $gapService->generate($this->customer, $competitors);
            }
        }

        Log::info('AnalyzeWarRoomCompetitor: Complete', [
            'customer_id' => $this->customer->id,
            'competitor_id' => $this->competitor->id,
        ]);
    }
}
