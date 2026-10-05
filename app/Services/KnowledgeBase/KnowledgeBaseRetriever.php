<?php

namespace App\Services\KnowledgeBase;

use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\KnowledgeBaseSearchService;

class KnowledgeBaseRetriever
{
    public function __construct(private readonly GeminiService $gemini) {}

    public function search(Customer $customer, string $question, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $service = new KnowledgeBaseSearchService($this->gemini);

        // A brief supplements retrieved passages; it never replaces the corpus.
        $brief = \App\Models\KnowledgeBase::where('customer_id', $customer->id)->whereNull('excluded_at')
            ->where('original_filename', 'onboarding-business-brief.txt')->whereNull('file_path')
            ->latest('updated_at')->first();
        $hasBrief = $brief && trim($brief->content) !== '';
        $hasAdditionalSources = ! $hasBrief || \App\Models\KnowledgeBase::where('customer_id', $customer->id)
            ->whereNull('excluded_at')->whereKeyNot($brief->id)->where('content', '!=', '')->exists()
            || \App\Models\CustomerPage::where('customer_id', $customer->id)->whereNotNull('embedding')
                ->whereNotExists(fn ($sources) => $sources->selectRaw('1')->from('knowledge_bases')
                    ->whereColumn('knowledge_bases.customer_id', 'customer_pages.customer_id')->whereColumn('knowledge_bases.url', 'customer_pages.url'))->exists();
        $results = $hasAdditionalSources && (! $hasBrief || $limit > 1)
            ? $service->passages($customer->id, $question, $hasBrief ? $limit - 1 : $limit, 'first_campaign', $hasBrief ? [$brief->id] : []) : [];
        if ($hasBrief) {
            array_unshift($results, ['url' => $customer->website ?? '', 'excerpt' => '[Business brief, version '.$brief->source_version.'] '.mb_substr($brief->content, 0, 1200)]);
            \Illuminate\Support\Facades\DB::table('knowledge_retrievals')->insert(['customer_id' => $customer->id, 'knowledge_base_id' => $brief->id, 'source_version' => $brief->source_version, 'purpose' => 'first_campaign', 'query' => $question, 'positions' => json_encode([0]), 'retrieved_at' => now()]);
        }

        return array_map(fn ($result) => ['url' => (string) $result['url'], 'excerpt' => $result['excerpt']], array_slice($results, 0, $limit));
    }
}
