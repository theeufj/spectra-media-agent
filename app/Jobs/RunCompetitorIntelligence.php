<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Agents\CompetitorIntelligenceAgent;
use App\Services\Agents\CompetitorDiscoveryAgent;
use App\Services\Agents\CompetitorAnalysisAgent;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunCompetitorIntelligence Job
 * 
 * Runs the full competitive intelligence pipeline for a customer:
 * 1. Discover competitors via Google Search
 * 2. Scrape and analyze competitor websites
 * 3. Fetch Auction Insights from Google Ads
 * 4. Generate counter-strategies
 */
class RunCompetitorIntelligence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Customer $customer;
    protected bool $fullRefresh;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     *
     * @param Customer $customer The customer to analyze
     * @param bool $fullRefresh Whether to re-analyze all competitors
     */
    public function __construct(Customer $customer, bool $fullRefresh = false)
    {
        $this->customer = $customer;
        $this->fullRefresh = $fullRefresh;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('RunCompetitorIntelligence: Starting job', [
            'customer_id' => $this->customer->id,
            'full_refresh' => $this->fullRefresh,
        ]);

        try {
            // Initialize services
            $gemini = new GeminiService();
            $discoveryAgent = new CompetitorDiscoveryAgent($gemini);
            $analysisAgent = new CompetitorAnalysisAgent($gemini);
            $intelligenceAgent = new CompetitorIntelligenceAgent(
                $gemini,
                $discoveryAgent,
                $analysisAgent
            );

            // Run full analysis
            $results = $intelligenceAgent->runFullAnalysis($this->customer);

            // Update customer with last analysis timestamp
            $this->customer->update([
                'competitor_analysis_at' => now(),
            ]);

            // Log results
            Log::info('RunCompetitorIntelligence: Job complete', [
                'customer_id' => $this->customer->id,
                'competitors_discovered' => $results['discovery']['competitors_saved'] ?? 0,
                'competitors_analyzed' => $results['analysis']['analyzed'] ?? 0,
                'has_counter_strategy' => isset($results['counter_strategy']['strategy']),
                'errors' => $results['errors'],
            ]);

            // Notify user if significant findings
            if ($this->hasSignificantFindings($results)) {
                $this->notifyUser($results);
            }

        } catch (\Exception $e) {
            Log::error('RunCompetitorIntelligence: Job failed', [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Check if there are significant findings to report.
     */
    protected function hasSignificantFindings(array $results): bool
    {
        // New competitors found
        if (($results['discovery']['competitors_saved'] ?? 0) >= 3) {
            return true;
        }

        // Counter-strategy generated
        if (isset($results['counter_strategy']['strategy'])) {
            return true;
        }

        // Competitors with high impression share discovered
        $auctionCompetitors = $results['auction_insights']['competitors_found'] ?? [];
        if (count($auctionCompetitors) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Notify user of competitive intelligence findings.
     */
    protected function notifyUser(array $results): void
    {
        // Get the primary user for this customer
        $user = $this->customer->users()->first();
        
        if (!$user) {
            return;
        }

        // Build notification summary
        $summary = [];
        
        if ($results['discovery']['competitors_saved'] ?? 0 > 0) {
            $summary[] = "{$results['discovery']['competitors_saved']} new competitors discovered";
        }
        
        if ($results['analysis']['analyzed'] ?? 0 > 0) {
            $summary[] = "{$results['analysis']['analyzed']} competitors analyzed";
        }
        
        if (isset($results['counter_strategy']['strategy'])) {
            $summary[] = "New counter-strategy generated";
        }

        // TODO: Send email notification
        // Mail::to($user)->send(new CompetitorIntelligenceReport($this->customer, $results));
        
        Log::info('RunCompetitorIntelligence: Would notify user', [
            'user_id' => $user->id,
            'summary' => $summary,
        ]);
    }
}
