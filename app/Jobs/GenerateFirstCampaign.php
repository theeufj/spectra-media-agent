<?php

namespace App\Jobs;

use App\Mail\FirstCampaignReady;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPage;
use App\Prompts\FirstCampaignPrompt;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseRetriever;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Build a customer's first campaign for them, from their own website.
 *
 * By the time the crawl finishes the platform knows their brand voice,
 * audience, messaging and every page they sell from. Asking them to then fill
 * in a nine-step wizard describing the business we just read is the reason
 * sixteen accounts pasted a URL and none of them ever built anything.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: charge anybody, or set a budget anyone is
 * committed to. The model proposes a daily budget and explains it, but
 * budget_confirmed_at stays null and deployment refuses until a human accepts
 * the figure — because that number is multiplied by seven and charged up front.
 *
 * Never throws. This runs unbidden at the end of onboarding; a failure here
 * means the customer does not get a bonus, not that their signup broke.
 */
class GenerateFirstCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many pages with real text on them the crawl must have found.
     *
     * COUNTED ON CONTENT, NOT ON ROWS. This gate originally counted
     * customer_pages, and production showed why that fails: one Shopify store
     * had 1,236 crawled pages of which 3 held more than 300 characters — the
     * crawler had captured navigation chrome and "there was a problem loading
     * this website" instead of product text. Another had 51 pages and 1 with
     * content. Both would have sailed through a row count and been handed a
     * generic campaign, which is the exact "worse than nothing" first
     * impression this gate exists to prevent.
     */
    public const MIN_SUBSTANTIVE_PAGES = 5;

    /**
     * Below this a page is chrome — a menu, a cookie banner, an error. Chosen
     * against real data: the healthy accounts average 2,000–4,500 characters a
     * page, the unusable ones 100–240.
     */
    public const MIN_CONTENT_CHARS = 300;

    public function __construct(public Customer $customer) {}

    public function handle(GeminiService $gemini, KnowledgeBaseRetriever $knowledgeBase): void
    {
        try {
            // Re-checked rather than trusted from dispatch time: a queued
            // job can run minutes later, by which point the customer may
            // have built a campaign themselves.
            if (! self::qualifies($this->customer)) {
                return;
            }

            $brand = $this->customer->brandGuideline;
            $pageTitles = CustomerPage::where('customer_id', $this->customer->id)
                ->orderBy('id')
                ->limit(40)
                ->pluck('title')
                ->filter()
                ->values()
                ->all();

            // The campaign has to be about what they actually sell, in their own
            // words. Brand guidelines are a summary of the site and page titles
            // are its table of contents; neither says what is on offer. This
            // pulls the pages that answer that question straight from the
            // knowledge base the crawl built.
            $pages = $knowledgeBase->search(
                $this->customer,
                'What products or services does this business sell, who buys them, and what makes them worth choosing?',
                limit: 10,
            );

            $response = $gemini->generateContent(
                model: config('ai.models.default'),
                prompt: FirstCampaignPrompt::generate($this->customer, $brand, $pageTitles, $pages),
                config: ['temperature' => 0.6, 'maxOutputTokens' => 1500],
                systemInstruction: FirstCampaignPrompt::systemInstruction(),
                context: ['customer_id' => $this->customer->id, 'task_type' => 'strategy'],
            );

            $brief = $this->decode($response['text'] ?? '');

            if ($brief === null) {
                Log::warning('GenerateFirstCampaign: model returned nothing usable', [
                    'customer_id' => $this->customer->id,
                ]);

                return;
            }

            $campaign = $this->createFrom($brief);

            Log::info('GenerateFirstCampaign: campaign created', [
                'customer_id' => $this->customer->id,
                'campaign_id' => $campaign->id,
            ]);

            // Same path a hand-built campaign takes, so the customer has
            // something real to review rather than an empty shell.
            GenerateStrategy::dispatch($campaign);

            $this->notify($campaign);
        } catch (\Throwable $e) {
            // report() reaches the admin exception dashboard; Log::error alone
            // does not. Onboarding must survive this failing.
            report($e);
            Log::error('GenerateFirstCampaign failed', [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Does this account look like a real business with a real website?
     *
     * Public and static because the caller needs to ask before dispatching:
     * ExtractBrandGuidelines sends a different email depending on the answer,
     * and it cannot wait for a queued job to tell it.
     */
    public static function qualifies(Customer $customer): bool
    {
        if (! config('first_campaign.enabled', true)) {
            return false;
        }

        // One per customer, and never over the top of work they did themselves.
        if (Campaign::where('customer_id', $customer->id)->exists()) {
            return false;
        }

        // The knowledge base, not customer_pages: this is the text the campaign
        // is actually written from, so it is the thing worth counting.
        $substantive = DB::table('knowledge_bases')
            ->where('customer_id', $customer->id)
            ->whereRaw('length(content) >= ?', [self::MIN_CONTENT_CHARS])
            ->count();

        if ($substantive < self::MIN_SUBSTANTIVE_PAGES) {
            Log::info('GenerateFirstCampaign skipped: crawl found too little readable content', [
                'customer_id' => $customer->id,
                'substantive_pages' => $substantive,
                'total_pages' => CustomerPage::where('customer_id', $customer->id)->count(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Tell them it is waiting.
     *
     * Guarded per recipient: one bad address must not stop the others hearing
     * about it, and the campaign exists either way.
     */
    private function notify(Campaign $campaign): void
    {
        foreach ($this->customer->users as $user) {
            try {
                Mail::to($user->email)->queue(new FirstCampaignReady($campaign, $user->name));
            } catch (\Throwable $e) {
                report($e);
                Log::error('Failed to send first-campaign email', [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        // Models wrap JSON in code fences however firmly you ask them not to.
        $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)) ?? '');

        $decoded = json_decode($clean, true);

        if (! is_array($decoded) || ! isset($decoded['name'], $decoded['daily_budget'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function createFrom(array $brief): Campaign
    {
        $daily = round((float) $brief['daily_budget'], 2);

        // A model figure is a suggestion, not a mandate. Clamped so a
        // misplaced decimal cannot present someone with a four-figure daily
        // spend, and floored so the campaign is not dead on arrival.
        $daily = max(5.0, min(200.0, $daily));

        return Campaign::create([
            'customer_id' => $this->customer->id,
            'name' => Str::limit(trim((string) $brief['name']), 60, ''),
            'reason' => $brief['reason'] ?? null,
            'goals' => $brief['goals'] ?? null,
            'target_market' => $brief['target_market'] ?? null,
            'voice' => $brief['voice'] ?? null,
            'primary_kpi' => $brief['primary_kpi'] ?? null,
            'product_focus' => $brief['product_focus'] ?? null,
            'landing_page_url' => $this->customer->website,
            'platforms' => ['google'],
            'daily_budget' => $daily,
            // Seven days at the suggested rate — the same window deployment
            // charges for, so the two figures cannot disagree.
            'total_budget' => round($daily * 7, 2),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'auto_generated_at' => now(),
            // Deliberately null: nothing may be charged against a number no
            // human has looked at. DeploymentController enforces this.
            'budget_confirmed_at' => null,
            'budget_rationale' => $brief['budget_rationale'] ?? null,
        ]);
    }
}
