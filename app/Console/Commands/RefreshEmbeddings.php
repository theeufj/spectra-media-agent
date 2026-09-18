<?php

namespace App\Console\Commands;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use App\Support\Embeddings;
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
        // Resolved rather than constructed, so a test can substitute the
        // embedder. A command that can only be exercised against the live
        // API is a command whose failures are found in production.
        $gemini = app(GeminiService::class);

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
        /*
           This read json_decode($kb->content) and returned early unless the
           result was an array. The column holds the cleaned page text, not
           JSON, and always has — so the guard was true for every row in the
           table and the command re-embedded nothing, ever. It still advanced
           the progress bar and still printed "Done.", which is why it read as
           a working repair tool for as long as it existed.
        */
        $chunks = Embeddings::split((string) $kb->content);

        if ($chunks === []) {
            return;
        }

        $allEmbeddings = [];
        $usedModels = [];

        foreach ($chunks as $chunk) {
            if (trim($chunk) === '') {
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

        // One vector per row, averaged — $allEmbeddings is a list of chunk
        // vectors, and handing that to new Vector() builds a nested array
        // where the column expects a flat one.
        $averaged = Embeddings::average($allEmbeddings);

        if ($averaged === null) {
            $this->warn(" Chunk embeddings could not be combined for KB #{$kb->id}");

            return;
        }

        $kb->update([
            // new Vector(), matching every other write path — the column casts
            // to Vector and a raw array went in as a JSON-ish literal.
            'embedding' => new Vector($averaged),
            // A file whose chunks fell back mid-run is not in a single space.
            'embedding_model' => count(array_unique(array_filter($usedModels))) === 1
                ? reset($usedModels)
                : null,
        ]);
    }
}
