<?php

namespace App\Jobs;

use App\Models\ABTest;
use App\Models\Notification;
use App\Services\Agents\ABTestingAgent;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateABTests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \App\Jobs\Concerns\RecordsAgentRun;

    public int $tries = 2;
    public int $timeout = 300;

    public function handle(ABTestingAgent $agent, NotificationService $notifications): void
    {
        $runStart = $this->startRun();

        $tests = ABTest::running()
            ->with(['campaign.customer.users'])
            ->get();

        Log::info("EvaluateABTests: Evaluating {$tests->count()} running tests");

        $applied = 0;
        $errors = 0;

        foreach ($tests as $test) {
            try {
                $result = $agent->evaluateTest($test);

                if ($result['action'] === 'significant') {
                    // Auto-apply results
                    $applyResult = $agent->applyResults($test);
                    $applied++;

                    // Notify the campaign owner
                    $user = $this->campaignOwner($test);
                    if ($user) {
                        $winner = $result['winner'];
                        $lift = round($result['results']['lift_pct'] ?? 0, 1);
                        $confidence = round(($result['confidence'] ?? 0) * 100, 1);

                        $notifications->notify(
                            $user,
                            Notification::TYPE_AB_TEST_COMPLETE,
                            'A/B Test Winner Found',
                            "Your {$test->test_type} test reached {$confidence}% confidence. " .
                            "\"{$winner['label']}\" won with a {$lift}% lift in CTR.",
                            route('campaigns.show', $test->campaign_id),
                            'View Results',
                            $test->campaign->customer,
                            [
                                'test_id' => $test->id,
                                'test_type' => $test->test_type,
                                'winner' => $winner['label'],
                                'confidence' => $confidence,
                                'lift_pct' => $lift,
                                'replacements' => $applyResult['replacements'] ?? [],
                            ]
                        );
                    }
                }

                if ($result['action'] === 'stopped') {
                    $user = $this->campaignOwner($test);
                    if ($user) {
                        $notifications->notify(
                            $user,
                            Notification::TYPE_SYSTEM_INFO,
                            'A/B Test Stopped',
                            "Your {$test->test_type} test was stopped after {$this->maxTestDurationLabel($test)} without reaching significance.",
                            route('campaigns.show', $test->campaign_id),
                            'View Campaign',
                            $test->campaign->customer
                        );
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('EvaluateABTests: Failed to evaluate test', [
                    'test_id' => $test->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->finishRun($runStart, actions: $applied, errors: $errors, scope: $tests->count() . ' tests');
    }

    /**
     * The user to notify for a test: the customer's owner, falling back to any member.
     * Customer <-> User is many-to-many (pivot role); there is no `user` relationship.
     */
    protected function campaignOwner(ABTest $test): ?\App\Models\User
    {
        $customer = $test->campaign?->customer;
        if (!$customer) {
            return null;
        }

        return $customer->users()->wherePivot('role', 'owner')->first()
            ?? $customer->users()->first();
    }

    protected function maxTestDurationLabel(ABTest $test): string
    {
        $days = $test->started_at->diffInDays(now());
        return "{$days} days";
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('EvaluateABTests failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
        $this->recordRunFailure($exception);
    }
}
