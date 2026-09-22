<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\SEO\BacklinkAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class RunBacklinkAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $customerId, public string $domain) {}

    public function uniqueId(): string
    {
        return $this->customerId.':'.$this->domain;
    }

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            return;
        }
        $service = new BacklinkAnalysisService($customer);
        $key = $service->key($this->domain).':run';
        Cache::put($key, ['status' => 'running'], now()->addMinutes(10));
        $profile = $service->analyze($this->domain, true);
        Cache::put($key, [
            'status' => $profile['status'] === 'unavailable' ? 'failed' : 'completed',
            'completed_at' => now()->toIso8601String(),
            'error' => $profile['status'] === 'unavailable' ? 'Backlink data could not be retrieved. Any previous successful report is retained. Please retry or check the provider connection.' : null,
        ], now()->addDays(7));
    }

    public function failed(\Throwable $e): void
    {
        $customer = Customer::find($this->customerId);
        if ($customer) {
            $service = new BacklinkAnalysisService($customer);
            Cache::put($service->key($this->domain).':run', ['status' => 'failed',
                'error' => 'Analysis was interrupted. Your previous report is retained; please retry.'], now()->addDays(7));
        }
    }
}
