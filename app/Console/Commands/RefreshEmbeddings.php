<?php

namespace App\Console\Commands;

use App\Models\CustomerPage;
use App\Models\KnowledgeBase;
use App\Services\GeminiService;
use Illuminate\Console\Command;
use Pgvector\Laravel\Vector;

class RefreshEmbeddings extends Command
{
    protected $signature = 'embeddings:refresh
                            {--model=gemini-embedding-2-preview : Embedding model to use}
                            {--only=all : Only refresh "pages", "knowledge", or "all"}';

    protected $description = 'Re-generate embeddings for all customer pages and knowledge bases using the latest embedding model';

    public function handle(): int
    {
        $model = $this->option('model');
        $only = $this->option('only');
        $gemini = new GeminiService();

        if (in_array($only, ['all', 'pages'])) {
            $this->refreshCustomerPages($gemini, $model);
        }

        if (in_array($only, ['all', 'knowledge'])) {
            $this->refreshKnowledgeBases($gemini, $model);
        }

        $this->info('Done.');
        return Command::SUCCESS;
    }

    private function refreshCustomerPages(GeminiService $gemini, string $model): void
    {
        $pages = CustomerPage::whereNotNull('content')->where('content', '!=', '')->get();
        $this->info("Re-embedding {$pages->count()} customer pages...");
        $bar = $this->output->createProgressBar($pages->count());

        foreach ($pages as $page) {
            $text = substr(
                $page->title . "\n" . $page->meta_description . "\n" . $page->content,
                0,
                8000
            );

            $embedding = $gemini->embedContent($model, $text);

            if ($embedding) {
                $page->update(['embedding' => new Vector($embedding)]);
            } else {
                $this->warn(" Failed: {$page->url}");
            }

            $bar->advance();
            usleep(100_000); // 100ms rate-limit buffer
        }

        $bar->finish();
        $this->newLine();
    }

    private function refreshKnowledgeBases(GeminiService $gemini, string $model): void
    {
        $kbs = KnowledgeBase::whereNotNull('content')->where('content', '!=', '')->get();
        $this->info("Re-embedding {$kbs->count()} knowledge bases...");
        $bar = $this->output->createProgressBar($kbs->count());

        foreach ($kbs as $kb) {
            $chunks = json_decode($kb->content, true);

            if (!is_array($chunks) || empty($chunks)) {
                $bar->advance();
                continue;
            }

            $allEmbeddings = [];
            foreach ($chunks as $chunk) {
                if (empty(trim($chunk))) {
                    continue;
                }

                $embedding = $gemini->embedContent($model, $chunk);

                if ($embedding) {
                    $allEmbeddings[] = $embedding;
                } else {
                    $this->warn(" Failed chunk for KB #{$kb->id}");
                }

                usleep(100_000); // 100ms rate-limit buffer
            }

            if (!empty($allEmbeddings)) {
                $kb->update(['embedding' => $allEmbeddings]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }
}
