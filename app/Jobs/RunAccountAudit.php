<?php

namespace App\Jobs;

use App\Models\AuditSession;
use App\Services\Agents\AccountAuditAgent;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAccountAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(
        protected AuditSession $auditSession
    ) {}

    public function handle(): void
    {
        Log::info("RunAccountAudit: Starting audit for session {$this->auditSession->id}");

        $this->auditSession->update(['status' => 'running']);

        try {
            $agent = new AccountAuditAgent(app(GeminiService::class));
            $results = $agent->audit($this->auditSession);

            $this->auditSession->update([
                'status' => 'completed',
                'audit_results' => $results,
                'score' => $results['score'] ?? 0,
                'completed_at' => now(),
            ]);

            Log::info("RunAccountAudit: Completed audit for session {$this->auditSession->id}", [
                'score' => $results['score'],
                'findings' => count($results['findings'] ?? []),
            ]);

        } catch (\Exception $e) {
            Log::error("RunAccountAudit: Failed for session {$this->auditSession->id}", [
                'error' => $e->getMessage(),
            ]);

            $this->auditSession->update([
                'status' => 'failed',
                'audit_results' => ['error' => 'Audit failed. Please try again.'],
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RunAccountAudit failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
