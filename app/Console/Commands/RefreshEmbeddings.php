<?php

namespace App\Console\Commands;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pgvector\Laravel\Vector;

class RefreshEmbeddings extends Command
{
    protected $signature = 'embeddings:refresh
                            {--model= : Embedding model to use (defaults to config ai.models.embedding)}
                            {--only=all : Only refresh "pages", "knowledge", or "all"}
                            {--mismatched : Only rows not already embedded by the target model}';

    protected $description = 'Re-generate embeddings for customer pages and knowledge bases';

    public function handle(): int
    {
        // Was hardcoded to gemini-embedding-2-preview, so changing
        // AI_MODEL_EMBEDDING re-embedded the corpus into a space the
        // application no longer queried with.
        $model = $this->option('model') ?: config('ai.models.embedding');

        if (! $model) {
            $this->error('No embedding model configured. Set AI_MODEL_EMBEDDING or pass --model.');

            return Command::FAILURE;
        }

        $only = $this->option('only');
        $gemini = new GeminiService;

        $this->info("Target embedding model: {$model}");

        if ($this->option('mismatched')) {
            $this->line('Only rows whose stored vectors came from a different model (or none recorded).');
        }

        if (in_array($only, ['all', 'pages'], true)) {
            $this->refreshCustomerPages($gemini, $model);
        }

        if (in_array($only, ['all', 'knowledge'], true)) {
            $this->refreshKnowledgeBases($gemini, $model);
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }

    /**
     * Restrict a query to rows that are not already in the target space.
     */
    private function scope(Builder $query, string $model): Builder
    {
        if (! $this->option('mismatched')) {
            return $query;
        }

        return $query->where(
            fn (Builder $q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $model)
        );
    }

    private function refreshCustomerPages(GeminiService $gemini, string $model): void
    {
        $query = $this->scope(
            CustomerPage::whereNotNull('content')->where('content', '!=', ''),
            $model,
        );

        $total = (clone $query)->count();
        $this->info("Re-embedding {$total} customer pages...");
        $bar = $this->output->createProgressBar($total);

        // chunkById rather than get(): this table is the whole crawled corpus
        // for every customer, and loading it into memory to iterate it is the
        // kind of thing that works until it is the only thing that doesn't.
        $query->chunkById(100, function ($pages) use ($gemini, $model, $bar) {
            /** @var CustomerPage $page */
            foreach ($pages as $page) {
                $text = substr(
                    $page->title."\n".$page->meta_description."\n".$page->content,
                    0,
                    8000
                );

                $embedding = $gemini->embedContent($model, $text, [], $usedModel);

                if ($embedding) {
                    $page->update([
                        'embedding' => new Vector($embedding),
                        'embedding_model' => $usedModel,
                    ]);
                } else {
                    $this->warn(" Failed: {$page->url}");
                }

                $bar->advance();
                usleep(100_000); // 100ms rate-limit buffer
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function refreshKnowledgeBases(GeminiService $gemini, string $model): void
    {
        $query = $this->scope(
            KnowledgeBase::whereNotNull('content')->where('content', '!=', ''),
            $model,
        );

        $total = (clone $query)->count();
        $this->info("Re-embedding {$total} knowledge bases...");
        $bar = $this->output->createProgressBar($total);

        $query->chunkById(100, function ($kbs) use ($gemini, $model, $bar) {
            /** @var KnowledgeBase $kb */
            foreach ($kbs as $kb) {
                $this->refreshKnowledgeBase($gemini, $model, $kb);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function refreshKnowledgeBase(GeminiService $gemini, string $model, KnowledgeBase $kb): void
    {
        $chunks = json_decode($kb->content, true);

        if (! is_array($chunks) || $chunks === []) {
            return;
        }

        $allEmbeddings = [];
        $usedModels = [];

        foreach ($chunks as $chunk) {
            if (! is_string($chunk) || trim($chunk) === '') {
                continue;
            }

            $embedding = $gemini->embedContent($model, $chunk, [], $usedModel);

            if ($embedding) {
                $allEmbeddings[] = $embedding;
                $usedModels[] = $usedModel;
            } else {
                $this->warn(" Failed chunk for KB #{$kb->id}");
            }

            usleep(100_000); // 100ms rate-limit buffer
        }

        if ($allEmbeddings === []) {
            return;
        }

        $kb->update([
            // new Vector(), matching every other write path — the column casts
            // to Vector and a raw array went in as a JSON-ish literal.
            'embedding' => new Vector($allEmbeddings),
            // A file whose chunks fell back mid-run is not in a single space.
            'embedding_model' => count(array_unique(array_filter($usedModels))) === 1
                ? reset($usedModels)
                : null,
        ]);
    }
}
