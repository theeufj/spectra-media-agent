<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorizonWorkerResourceTest extends TestCase
{
    private function productionPlan(?string $minimum = null, ?string $maximum = null): ProvisioningPlan
    {
        $repository = Env::getRepository();
        $overrides = ['HORIZON_DEFAULT_MIN_PROCESSES' => $minimum, 'HORIZON_DEFAULT_MAX_PROCESSES' => $maximum];
        $original = [];
        foreach ($overrides as $key => $value) {
            $original[$key] = $repository->get($key);
            $repository->clear($key);
            if ($value !== null) {
                $repository->set($key, $value);
            }
        }
        try {
            $configuration = require config_path('horizon.php');

            return new ProvisioningPlan('worker-resource-test', $configuration['environments'], $configuration['defaults']);
        } finally {
            foreach ($original as $key => $value) {
                $repository->clear($key);
                if ($value !== null) {
                    $repository->set($key, $value);
                }
            }
        }
    }

    public function test_production_workers_release_native_handles_between_jobs_without_changing_queue_retry_policies(): void
    {
        $plan = $this->productionPlan();
        $default = $plan->optionsFor('production', 'supervisor-1');
        $safety = $plan->optionsFor('production', 'spend-safety');
        $notifications = $plan->optionsFor('production', 'supervisor-2');
        foreach ([$default, $safety, $notifications] as $options) {
            $this->assertInstanceOf(SupervisorOptions::class, $options);
        }
        $this->assertSame('default', $default->queue);
        $this->assertSame(1, $default->minProcesses);
        $this->assertSame(2, $default->maxProcesses);
        $this->assertSame(1, $default->maxJobs);
        $this->assertSame(3600, $default->maxTime);
        $this->assertSame(3600, $default->timeout);
        $this->assertSame(1, $default->maxTries);
        $this->assertSame('spend-safety', $safety->queue);
        $this->assertSame(1, $safety->minProcesses);
        $this->assertSame(1, $safety->maxProcesses);
        $this->assertSame(1, $safety->maxJobs);
        $this->assertSame(3600, $safety->maxTime);
        $this->assertSame(150, $safety->timeout);
        $this->assertSame(3, $safety->maxTries);
        $this->assertSame('notifications', $notifications->queue);
        $this->assertSame(1, $notifications->minProcesses);
        $this->assertSame(1, $notifications->maxProcesses);
        $this->assertSame(60, $notifications->timeout);
        $this->assertSame(3, $notifications->maxTries);
    }

    public static function processOverrides(): array
    {
        return [
            'larger measured host' => ['2', '4', 2, 4],
            'minimum cannot exceed the process ceiling' => ['5', '2', 2, 2],
            'invalid limits cannot remove all default workers' => ['0', '-1', 1, 1],
        ];
    }

    #[DataProvider('processOverrides')]
    public function test_process_overrides_keep_dedicated_workers_and_recycling_intact(string $minimum, string $maximum, int $expectedMinimum, int $expectedMaximum): void
    {
        $plan = $this->productionPlan($minimum, $maximum);
        $default = $plan->optionsFor('production', 'supervisor-1');
        $this->assertSame($expectedMinimum, $default->minProcesses);
        $this->assertSame($expectedMaximum, $default->maxProcesses);
        $this->assertSame(1, $default->maxJobs);
        foreach (['spend-safety', 'supervisor-2'] as $name) {
            $this->assertSame(1, $plan->optionsFor('production', $name)->maxProcesses);
        }
    }
}
