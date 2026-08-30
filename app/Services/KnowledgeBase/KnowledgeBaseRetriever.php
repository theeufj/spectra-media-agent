<?php

namespace App\Services\KnowledgeBase;

use App\Models\Customer;
use App\Services\GeminiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find the pages of a customer's own site that answer a question.
 *
 * The crawl stores every page's text alongside a 3072-dimension embedding in a
 * real pgvector column, so relevance is a `<=>` ordering in the database. The
 * one other place that searches this data
 * (KnowledgeBaseController@search) loads every row into PHP and computes
 * cosine similarity in a loop — fine for a handful of pages, and the largest
 * account has 1,236.
 *
 * TENANT SCOPING IS NOT OPTIONAL HERE. Every query is bound to one customer_id
 * in SQL, not filtered afterwards. This content feeds AI prompts, and a
 * mis-scoped retrieval would put one customer's website into another
 * customer's campaign.
 */
class KnowledgeBaseRetriever
{
    /** Per-page character cap. Enough to convey what a page sells; short enough that ten fit in a prompt. */
    private const EXCERPT_CHARS = 1200;

    /**
     * Shorter than this and the row is chrome, not content — a nav bar, a
     * cookie banner, or a crawler error captured as if it were the page. One
     * production store has 1,236 crawled pages and 3 above this threshold;
     * without the filter a prompt would be padded with 100-character
     * fragments that say nothing about the business.
     */
    private const MIN_CONTENT_CHARS = 300;

    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * The customer's own pages most relevant to a question, most relevant first.
     *
     * Returns an empty array rather than throwing when embeddings are missing
     * or the embedding call fails — callers should degrade to whatever they had
     * before, not lose their whole job to a retrieval miss.
     *
     * @return list<array{url: string, excerpt: string}>
     */
    public function search(Customer $customer, string $question, int $limit = 10): array
    {
        try {
            $queryEmbedding = $this->gemini->embedContent(
                config('ai.models.embedding'),
                $question,
                ['customer_id' => $customer->id],
                $queryModel,
            );

            if (! is_array($queryEmbedding) || $queryEmbedding === [] || ! $queryModel) {
                Log::warning('KnowledgeBaseRetriever: no query embedding', ['customer_id' => $customer->id]);

                return [];
            }

            // pgvector wants a bracketed literal, and it must be bound as a
            // parameter — never interpolated, even though the values are floats
            // we generated.
            $vector = '['.implode(',', array_map(fn ($v) => (float) $v, $queryEmbedding)).']';

            $rows = DB::select(
                <<<'SQL'
                SELECT url, content
                FROM knowledge_bases
                WHERE customer_id = ?
                  AND embedding IS NOT NULL
                  AND embedding_model = ?
                  AND content IS NOT NULL
                  AND length(content) >= ?
                ORDER BY embedding <=> ?::vector
                LIMIT ?
                SQL,
                [$customer->id, $queryModel, self::MIN_CONTENT_CHARS, $vector, $limit],
            );

            return array_map(fn ($row) => [
                'url' => (string) $row->url,
                'excerpt' => $this->excerpt((string) $row->content),
            ], $rows);
        } catch (\Throwable $e) {
            report($e);
            Log::error('KnowledgeBaseRetriever failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Crawled page text carries navigation, cookie banners and footers. Collapse
     * the whitespace so the excerpt spends its budget on prose rather than on
     * the blank lines between menu items.
     */
    private function excerpt(string $content): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

        return mb_strlen($clean) > self::EXCERPT_CHARS
            ? mb_substr($clean, 0, self::EXCERPT_CHARS).'…'
            : $clean;
    }
}
