<?php

namespace Tests\Unit;

use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\AiCost;
use App\Models\ExceptionLog;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Prunable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operational tables must stay bounded; business data must not be pruned.
 *
 * Nothing pruned these, so they grew unbounded — 1,278 failed jobs back to April,
 * 1,697 captured exceptions, 1,932 agent runs. The volume was never the problem.
 * The problem was that 44 ProcessDailyAdSpendBilling failures were invisible
 * inside it, so nobody was paged.
 */
class RetentionTest extends TestCase
{
    /** @return array<string, array{0: class-string, 1: int}> */
    public static function prunedModels(): array
    {
        return [
            'captured exceptions' => [ExceptionLog::class, 90],
            'agent runs' => [AgentRun::class, 90],
            'agent activity' => [AgentActivity::class, 180],
        ];
    }

    #[DataProvider('prunedModels')]
    public function test_operational_tables_are_prunable(string $model, int $days): void
    {
        $this->assertContains(Prunable::class, class_uses_recursive($model));
    }

    #[DataProvider('prunedModels')]
    public function test_prunable_window_matches_the_documented_retention(string $model, int $days): void
    {
        $sql = (new $model)->prunable()->toRawSql();

        // Inside the cutoff -> kept; outside -> pruned.
        $this->assertStringContainsString(now()->subDays($days)->format('Y-m-d'), $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    #[DataProvider('prunedModels')]
    public function test_prunable_targets_the_models_own_table(string $model, int $days): void
    {
        // ExceptionLog maps to runtime_exceptions, so a convention-derived table
        // name here would silently prune nothing.
        $this->assertStringContainsString((new $model)->getTable(), (new $model)->prunable()->toRawSql());
    }

    /** @return array<string, array{0: class-string}> */
    public static function retainedModels(): array
    {
        return [
            'ai spend history' => [AiCost::class],
            'user notifications' => [Notification::class],
        ];
    }

    /**
     * Financial and user-facing records are deliberately exempt — deleting spend
     * history to save disk would be a bad trade.
     */
    #[DataProvider('retainedModels')]
    public function test_business_data_is_not_pruned(string $model): void
    {
        $this->assertNotContains(Prunable::class, class_uses_recursive($model));
    }
}
