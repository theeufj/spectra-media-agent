<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexKnowledgeBase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public function __construct(public KnowledgeBase $source, public ?int $version = null) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(KnowledgeBaseIndexer $indexer, GeminiService $gemini): void
    {
        $this->source->refresh();
        if ($this->source->excluded_at || ($this->version !== null && $this->version !== $this->source->source_version)
            || in_array($this->source->processing_status, ['queued', 'reading', 'failed'], true)) {
            return;
        }
        if (trim($this->source->content) === '') {
            $this->source->update(['processing_status' => 'failed', 'processing_error' => 'No readable text is available. Refresh the original source or add a business note.']);

            return;
        }
        if (! $this->source->chunks()->where('source_version', $this->source->source_version)->exists()) {
            $this->source = $indexer->prepare($this->source, $this->source->content);
        }
        if (! $indexer->index($this->source, $gemini, $this->version ?? $this->source->source_version)) {
            $this->release(60);
        }
    }

    public function failed(\Throwable $exception): void
    {
        KnowledgeBase::whereKey($this->source->id)->where('source_version', $this->version ?? $this->source->source_version)->whereNull('excluded_at')
            ->whereNotIn('processing_status', ['queued', 'reading', 'failed'])->update([
                'processing_status' => 'needs_attention',
                'processing_error' => 'We could not finish preparing this source for AI. Your readable text is retained. Retry from the source details.',
            ]);
    }
}
