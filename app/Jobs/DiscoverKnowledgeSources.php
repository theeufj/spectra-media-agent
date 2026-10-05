<?php

namespace App\Jobs;

use App\Models\KnowledgeImport;
use App\Services\KnowledgeBase\SourceDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DiscoverKnowledgeSources implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public KnowledgeImport $import) {}

    public function failed(\Throwable $exception): void
    {
        KnowledgeImport::whereKey($this->import->id)->whereIn('status', ['queued', 'discovering'])->update(['status' => 'failed', 'error' => 'Page discovery did not finish. Try another address, an individual page, or a business note.']);
    }

    public function handle(SourceDiscovery $discovery): void
    {
        $this->import->update(['status' => 'discovering', 'error' => null]);
        try {
            $candidates = $discovery->discover($this->import->website_url);
            if ($candidates === []) {
                throw new \RuntimeException('No public pages were found. Add an individual page or write a business note.');
            }
            $this->import->update(['status' => 'review', 'candidates' => $candidates]);
        } catch (\Throwable $e) {
            $this->import->update(['status' => 'failed', 'error' => 'We could not discover pages on this website. Check its address, add an individual page, or paste the information as a note.']);
            throw $e;
        }
    }
}
