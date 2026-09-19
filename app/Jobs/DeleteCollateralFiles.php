<?php

namespace App\Jobs;

use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DeleteCollateralFiles implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public array $paths) {}

    public function handle(): void
    {
        foreach ($this->paths as $path) {
            StorageHelper::delete($path, throwOnFailure: true);
        }
    }
}
