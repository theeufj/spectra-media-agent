<?php

namespace App\Services;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Support\Embeddings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Distance;

/** The same customer-bound, passage-level retrieval feeds the tester and AI. */
class KnowledgeBaseSearchService
{
    private ?int $campaignId = null;

    public function __construct(private GeminiService $gemini) {}

    public function forCampaign(int $campaignId): self
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function search(int $customerId, string $query, int $limit = 5): string
    {
        $results = $this->passages($customerId, $query, $limit, 'strategy');
        if ($results === []) {
            return 'No supporting business information found. Ask the customer rather than inventing a claim.';
        }

        return implode("\n\n---\n\n", array_map(fn ($result) => "[Source: {$result['source_name']} | {$result['url']} | version {$result['source_version']} | passage ".($result['position'] + 1)."]\n{$result['chunk']}", $results));
    }

    public function passages(int $customerId, string $query, int $limit = 10, string $purpose = 'test', array $excludedSourceIds = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $terms = array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($query)) ?: [],
            fn ($term) => (mb_strlen($term) >= 2 || is_numeric($term)) && ! in_array($term, [
                'what', 'does', 'this', 'that', 'with', 'from', 'have', 'the', 'and', 'for', 'are', 'how', 'who', 'our', 'your', 'business', 'about', 'can', 'sell',
            ], true))));
        $results = [];
        $base = KnowledgeBaseChunk::query()->where('customer_id', $customerId)
            ->whereNotIn('knowledge_base_id', $excludedSourceIds)
            ->whereHas('source', fn ($sources) => $sources->whereNull('excluded_at')
                ->whereColumn('knowledge_bases.source_version', 'knowledge_base_chunks.source_version'));
        if ($terms !== []) {
            $patterns = array_map(fn ($term) => '%'.addcslashes($term, '%_\\').'%', $terms);
            $rank = implode(' + ', array_fill(0, count($terms), 'case when content ilike ? then 1 else 0 end'));
            $lexical = (clone $base)->where(function ($where) use ($terms) {
                foreach ($terms as $term) {
                    $where->orWhere('content', 'ilike', '%'.addcslashes($term, '%_\\').'%');
                }
            })->orderByRaw('('.$rank.') desc', $patterns)->with('source')->limit(200)->get();
            foreach ($lexical as $chunk) {
                if (! $chunk->source) {
                    continue;
                }
                $score = $this->lexicalScore($chunk->content, $terms);
                $results[] = $this->result($chunk->source, $chunk->content, $chunk->position, $score, 'text');
            }
        }

        try {
            // A live query never spin-waits behind an import. Text retrieval
            // remains available when embeddings or provider capacity are absent.
            $cacheKey = 'knowledge-query:'.hash('sha256', config('ai.models.embedding').'|'.$query);
            $cached = Cache::get($cacheKey);
            if (! is_array($cached)) {
                $vector = $this->gemini->embedContent(config('ai.models.embedding'), $query, [
                    'customer_id' => $customerId, 'embedding_wait_ms' => 0, 'embedding_timeout_seconds' => 3,
                ], $queryModel);
                $cached = $vector && $queryModel ? ['vector' => $vector, 'model' => $queryModel] : null;
                if ($cached) {
                    Cache::put($cacheKey, $cached, now()->addMinutes(10));
                }
            }
            if ($cached) {
                $semantic = (clone $base)->where('embedding_model', $cached['model'])
                    ->whereNotNull('embedding')->nearestNeighbors('embedding', $cached['vector'], Distance::Cosine)
                    ->with('source')->limit($limit * 3)->get();
                foreach ($semantic as $chunk) {
                    if (! $chunk->source) {
                        continue;
                    }
                    $score = 1 - (float) $chunk->getAttribute('neighbor_distance');
                    if ($score >= 0.35) {
                        $results[] = $this->result($chunk->source, $chunk->content, $chunk->position,
                            $score + $this->lexicalScore($chunk->content, $terms), 'semantic');
                    }
                }
                // Existing corpora work immediately; a queued repair creates
                // passage vectors. Never compare a document from another space.
                $legacy = KnowledgeBase::where('customer_id', $customerId)->whereNull('excluded_at')
                    ->whereNotIn('id', $excludedSourceIds)
                    ->where('embedding_model', $cached['model'])->whereNotNull('embedding')
                    ->whereDoesntHave('chunks')
                    ->nearestNeighbors('embedding', $cached['vector'], Distance::Cosine)->limit($limit)->get();
                foreach ($legacy as $source) {
                    if (1 - (float) $source->getAttribute('neighbor_distance') >= 0.35) {
                        $results[] = $this->legacyResult($source, $terms, 1 - (float) $source->getAttribute('neighbor_distance'));
                    }
                }
                // Historical customer pages may predate the knowledge workspace.
                // A matching source (including excluded ones) always takes precedence,
                // so the mirror cannot duplicate or revive excluded evidence.
                $pages = CustomerPage::where('customer_id', $customerId)->where('embedding_model', $cached['model'])
                    ->whereNotNull('embedding')->whereNotExists(fn ($sources) => $sources->selectRaw('1')->from('knowledge_bases')
                    ->whereColumn('knowledge_bases.customer_id', 'customer_pages.customer_id')->whereColumn('knowledge_bases.url', 'customer_pages.url'))
                    ->nearestNeighbors('embedding', $cached['vector'], Distance::Cosine)->limit($limit)->get();
                foreach ($pages as $page) {
                    $similarity = 1 - (float) $page->getAttribute('neighbor_distance');
                    if ($similarity < 0.35) {
                        continue;
                    }
                    $passages = Embeddings::split($page->content, 1200);
                    $ranked = [];
                    foreach ($passages as $position => $passage) {
                        $ranked[$position] = $this->lexicalScore($passage, $terms);
                    }
                    arsort($ranked);
                    $position = array_key_first($ranked);
                    if ($position === null) {
                        continue;
                    }
                    $results[] = ['kb_id' => null, 'source_name' => $page->title ?: $page->url, 'url' => $page->url,
                        'source_version' => 1, 'position' => $position, 'chunk' => $passages[$position], 'excerpt' => $passages[$position],
                        'score' => $similarity + $ranked[$position], 'method' => 'semantic', 'legacy_page' => true];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Includes unembedded legacy records and human-written descriptions.
        if ($terms !== []) {
            $legacy = KnowledgeBase::where('customer_id', $customerId)->whereNull('excluded_at')
                ->whereNotIn('id', $excludedSourceIds)
                ->whereDoesntHave('chunks')->where(function ($where) use ($terms) {
                    foreach ($terms as $term) {
                        $where->orWhere('content', 'ilike', '%'.addcslashes($term, '%_\\').'%');
                    }
                })->limit(200)->get();
            foreach ($legacy as $source) {
                $results[] = $this->legacyResult($source, $terms, 0);
            }
        }
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);
        $seen = [];
        $perSource = [];
        $selected = [];
        foreach ($results as $result) {
            $key = rtrim($result['url'], '/').'|'.hash('sha256', $result['chunk']);
            $sourceKey = $result['kb_id'] ?? $result['url'];
            if (isset($seen[$key]) || ($perSource[$sourceKey] ?? 0) >= 2) {
                continue;
            }
            $seen[$key] = true;
            $perSource[$sourceKey] = ($perSource[$sourceKey] ?? 0) + 1;
            $selected[] = $result;
            if (count($selected) === $limit) {
                break;
            }
        }
        if ($purpose !== 'test') {
            foreach (collect($selected)->filter(fn ($result) => $result['kb_id'] !== null)->groupBy('kb_id') as $sourceResults) {
                $result = $sourceResults->first();
                DB::table('knowledge_retrievals')->insert([
                    'customer_id' => $customerId, 'knowledge_base_id' => $result['kb_id'],
                    'campaign_id' => $this->campaignId, 'source_version' => $result['source_version'],
                    'purpose' => $purpose, 'query' => $query, 'positions' => json_encode($sourceResults->pluck('position')->all()),
                    'retrieved_at' => now(),
                ]);
            }
        }

        return $selected;
    }

    private function lexicalScore(string $content, array $terms): float
    {
        $text = mb_strtolower($content);

        return count(array_filter($terms, fn ($term) => mb_strpos($text, $term) !== false)) / max(1, count($terms));
    }

    private function result(KnowledgeBase $source, string $content, int $position, float $score, string $method): array
    {
        return [
            'kb_id' => $source->id, 'source_name' => $source->title ?: $source->original_filename ?: $source->url,
            'url' => $source->url, 'source_version' => $source->source_version, 'position' => $position,
            'chunk' => $content, 'excerpt' => $content, 'score' => $score, 'method' => $method,
        ];
    }

    private function legacyResult(KnowledgeBase $source, array $terms, float $score): array
    {
        $passages = Embeddings::split(preg_replace('/\s+/u', ' ', $source->content) ?? $source->content, 1200);
        $ranked = [];
        foreach ($passages as $position => $passage) {
            $ranked[] = $this->result($source, $passage, $position, $score + $this->lexicalScore($passage, $terms), 'text');
        }
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $ranked[0] ?? $this->result($source, '', 0, 0, 'text');
    }
}
