<?php

namespace App\Services\KnowledgeBase;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Services\GeminiService;
use App\Support\Embeddings;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Vector;

class KnowledgeBaseIndexer
{
    public function prepare(KnowledgeBase $source, string $content, ?string $title = null): KnowledgeBase
    {
        return DB::transaction(function () use ($source, $content, $title) {
            $locked = KnowledgeBase::query()->lockForUpdate()->findOrFail($source->id);
            $hash = hash('sha256', $content);
            $previousHash = $locked->content_hash ?: (trim($locked->content) !== '' ? hash('sha256', $locked->content) : null);
            $changed = $previousHash !== null && $previousHash !== $hash;
            if ($changed && ! $locked->chunks()->where('source_version', $locked->source_version)->exists()) {
                foreach (Embeddings::split($locked->content, 1200) as $position => $passage) {
                    KnowledgeBaseChunk::firstOrCreate(['knowledge_base_id' => $locked->id, 'source_version' => $locked->source_version, 'position' => $position], ['customer_id' => $locked->customer_id, 'content' => $passage]);
                }
            }
            $version = (int) $locked->source_version + ($changed ? 1 : 0);
            $locked->update([
                'content' => $content, 'content_hash' => $hash, 'source_version' => $version,
                'title' => $title ?: $locked->title, 'fetched_at' => now(),
                'processing_status' => 'indexing', 'processing_error' => null,
                ...($changed ? ['embedding' => null, 'embedding_model' => null, 'indexed_at' => null] : []),
            ]);
            // Old passages remain immutable so a campaign's source version can
            // still be inspected after the website or document changes.
            foreach (Embeddings::split($content) as $position => $passage) {
                KnowledgeBaseChunk::firstOrCreate([
                    'knowledge_base_id' => $locked->id, 'source_version' => $version, 'position' => $position,
                ], ['customer_id' => $locked->customer_id, 'content' => $passage]);
            }

            return $locked;
        });
    }

    /** Returns false when the queue should resume remaining or fallback passages. */
    public function index(KnowledgeBase $source, GeminiService $gemini, int $version, ?string $target = null, bool $force = false): bool
    {
        $source->refresh();
        if ($source->excluded_at || $source->source_version !== $version || in_array($source->processing_status, ['queued', 'reading', 'failed'], true)) {
            return true;
        }
        $primary = config('ai.models.embedding');
        $target ??= $primary;
        $chunks = $source->chunks()->where('source_version', $version)->orderBy('position')->get();
        foreach ($chunks as $chunk) {
            if (! $force && $chunk->embedding && $chunk->embedding_model === $target) {
                continue;
            }
            // Interactive search must never queue behind a crawling worker.
            // A busy limiter releases this job rather than sleeping in PHP.
            $vector = $gemini->embedContent($target, $chunk->content, [
                'customer_id' => $source->customer_id, 'embedding_wait_ms' => 0,
            ], $usedModel);
            if (! $vector || ! $usedModel) {
                break;
            }
            $chunk->update(['embedding' => new Vector($vector), 'embedding_model' => $usedModel]);
        }
        $chunks = $source->chunks()->where('source_version', $version)->get();
        $embedded = $chunks->filter(fn ($chunk) => $chunk->embedding !== null);
        $complete = $chunks->isNotEmpty() && $embedded->count() === $chunks->count();
        $ready = $complete && $chunks->every(fn ($chunk) => $chunk->embedding_model === $primary);
        $models = $embedded->pluck('embedding_model')->unique();
        $average = $complete && $models->count() === 1
            ? Embeddings::average($embedded->map(fn ($chunk) => $chunk->embedding->toArray())->all()) : null;
        $written = DB::transaction(function () use ($source, $version, $average, $models, $ready) {
            $written = KnowledgeBase::whereKey($source->id)->where('source_version', $version)->whereNull('excluded_at')
                ->whereNotIn('processing_status', ['queued', 'reading', 'failed'])->update([
                    'embedding' => $average ? new Vector($average) : null,
                    'embedding_model' => $average ? $models->first() : null,
                    'processing_status' => $ready ? 'ready' : 'needs_attention',
                    'processing_error' => $ready ? null : 'Your text is available for search. Some passages are still being prepared for AI; we will retry automatically.',
                    'indexed_at' => $ready ? now() : null,
                ]);

            if ($written && $average && $source->source_type === 'url') {
                \App\Models\CustomerPage::where('customer_id', $source->customer_id)->where('url', $source->url)->update([
                    'embedding' => new Vector($average), 'embedding_model' => $models->first(),
                ]);
            }

            return $written;
        });

        return ! $written || $ready;
    }
}
