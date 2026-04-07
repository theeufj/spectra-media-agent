<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupTemporaryFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $deleted = 0;

        // Clean up proposal files older than 90 days
        $proposalFiles = Storage::disk('local')->files('proposals');
        foreach ($proposalFiles as $file) {
            $lastModified = Storage::disk('local')->lastModified($file);
            if ($lastModified < now()->subDays(90)->timestamp) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        // Clean up temporary image generation files older than 7 days
        $tempFiles = Storage::disk('local')->files('temp');
        foreach ($tempFiles as $file) {
            $lastModified = Storage::disk('local')->lastModified($file);
            if ($lastModified < now()->subDays(7)->timestamp) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        Log::info("CleanupTemporaryFiles: Deleted {$deleted} expired files");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CleanupTemporaryFiles failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
