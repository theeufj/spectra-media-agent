<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Testing\SandboxAgentRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSandboxSimulation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public Customer $customer
    ) {}

    public function handle(SandboxAgentRunner $runner): void
    {
        if (!$this->customer->is_sandbox) {
            Log::warning('RunSandboxSimulation dispatched for non-sandbox customer', [
                'customer_id' => $this->customer->id,
            ]);
            return;
        }

        Log::info('Starting sandbox simulation', ['customer_id' => $this->customer->id]);

        $results = $runner->runAll($this->customer);

        $this->customer->update([
            'sandbox_results' => $results,
        ]);

        Log::info('Sandbox simulation completed', [
            'customer_id' => $this->customer->id,
            'agent_count' => count($results),
        ]);
    }
}
