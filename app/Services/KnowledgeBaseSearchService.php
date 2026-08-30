<?php

namespace App\Services;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;

class KnowledgeBaseSearchService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Search the customer's website content and knowledge base for a query.
     * Uses vector similarity search when embeddings are available, falls back to ILIKE.
     *
     * @param  string  $query  Natural-language search query
     * @param  int  $limit  Max results per source
     * @return string Formatted text suitable for returning as a tool response
     */
    public function search(int $customerId, string $query, int $limit = 5): string
    {
        $embedding = $this->gemini->embedContent(
            config('ai.models.embedding'),
            $query,
            [],
            $queryModel,
        );

        $results = [];

        if ($embedding && $queryModel) {
            $results = $this->vectorSearch($customerId, $embedding, $limit, $queryModel);
        }

        // Fall back to keyword search if vector search returned nothing
        if (empty($results)) {
            $results = $this->keywordSearch($customerId, $query, $limit);
        }

        if (empty($results)) {
            return "No relevant content found for: \"{$query}\"";
        }

        Log::info("KnowledgeBaseSearchService: query \"{$query}\" returned ".count($results).' results');

        return implode("\n\n---\n\n", $results);
    }

    /**
     * Nearest neighbours, restricted to vectors from the same embedding space.
     *
     * Two embedding models produce vectors of the same 3072 dimensions but in
     * different spaces, and GeminiService falls back between them on a 429.
     * Cosine distance across the two is noise, so a row embedded by the other
     * model is not a bad match — it is not a match at all, and ranking it
     * against the query would push genuine results out of the top N. Excluded
     * rows are still reachable through the ILIKE fallback below, and
     * `embeddings:refresh --mismatched` re-embeds them.
     */
    private function vectorSearch(int $customerId, array $embedding, int $limit, string $queryModel): array
    {
        $results = [];

        $pages = CustomerPage::where('customer_id', $customerId)
            ->where('embedding_model', $queryModel)
            ->nearestNeighbors('embedding', $embedding, Distance::Cosine)
            ->take($limit)
            ->get(['title', 'url', 'content', 'page_type', 'meta_description']);

        foreach ($pages as $page) {
            $label = ucfirst($page->page_type ?? 'page');
            $snippet = mb_substr(trim($page->content ?? $page->meta_description ?? ''), 0, 2000);
            if ($snippet) {
                $results[] = "[{$label}: {$page->title} — {$page->url}]\n{$snippet}";
            }
        }
        $kbs = KnowledgeBase::where('customer_id', $customerId)
            ->where('embedding_model', $queryModel)
            ->nearestNeighbors('embedding', $embedding, Distance::Cosine)
            ->take($limit)
            ->get(['url', 'content']);

        foreach ($kbs as $kb) {
            $snippet = mb_substr(trim($kb->content ?? ''), 0, 2000);
            if ($snippet) {
                $results[] = "[Knowledge Base: {$kb->url}]\n{$snippet}";
            }
        }

        return $results;
    }

    private function keywordSearch(int $customerId, string $query, int $limit): array
    {
        $results = [];
        $like = '%'.$query.'%';

        $pages = CustomerPage::where('customer_id', $customerId)
            ->where(function ($q) use ($like) {
                $q->where('content', 'ilike', $like)
                    ->orWhere('title', 'ilike', $like)
                    ->orWhere('meta_description', 'ilike', $like);
            })
            ->take($limit)
            ->get(['title', 'url', 'content', 'page_type', 'meta_description']);

        foreach ($pages as $page) {
            $label = ucfirst($page->page_type ?? 'page');
            $snippet = mb_substr(trim($page->content ?? $page->meta_description ?? ''), 0, 2000);
            if ($snippet) {
                $results[] = "[{$label}: {$page->title} — {$page->url}]\n{$snippet}";
            }
        }
        $kbs = KnowledgeBase::where('customer_id', $customerId)
            ->where('content', 'ilike', $like)
            ->take($limit)
            ->get(['url', 'content']);

        foreach ($kbs as $kb) {
            $snippet = mb_substr(trim($kb->content ?? ''), 0, 2000);
            if ($snippet) {
                $results[] = "[Knowledge Base: {$kb->url}]\n{$snippet}";
            }
        }

        return $results;
    }
}
