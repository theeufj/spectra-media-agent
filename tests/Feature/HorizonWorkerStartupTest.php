<?php

namespace Tests\Feature;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Laravel\Horizon\Console\WorkCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HorizonWorkerStartupTest extends TestCase
{
    public static function workerModes(): array
    {
        return [
            'supervised worker with default options' => [false, null],
            'single job with default options' => [true, null],
            'supervised worker with idle timeout' => [false, 15],
        ];
    }

    #[DataProvider('workerModes')]
    public function test_horizon_worker_starts_with_framework_worker_options(bool $once, ?int $idleTimeout): void
    {
        config(['horizon.fast_termination' => false]);

        // Exercise Horizon's real command and the framework's handle() path, but
        // stop at the Worker boundary so no Redis connection or job is needed.
        $cache = $this->createMock(Repository::class);
        $worker = $this->createMock(Worker::class);
        $worker->expects($this->once())->method('setName')->with('startup-regression')->willReturnSelf();
        $worker->expects($this->once())->method('setCache')->with($cache)->willReturnSelf();
        $worker->expects($this->once())
            ->method($once ? 'runNextJob' : 'daemon')
            ->with('redis', 'default', $this->callback(function (WorkerOptions $options) use ($idleTimeout): bool {
                $this->assertSame('startup-regression', $options->name);
                $this->assertEquals(3, $options->maxTries);
                $this->assertEquals($idleTimeout ?? 0, $options->stopWhenEmptyFor);

                return true;
            }))
            ->willReturn(0);

        $command = new WorkCommand($worker, $cache);
        $command->setLaravel($this->app);

        $arguments = [
            'connection' => 'redis',
            '--name' => 'startup-regression',
            '--queue' => 'default',
            '--supervisor' => 'supervisor-default',
            '--tries' => 3,
            '--json' => true,
        ];

        if ($once) {
            $arguments['--once'] = true;
        }

        if ($idleTimeout !== null) {
            $arguments['--stop-when-empty-for'] = $idleTimeout;
        }

        // A stale Horizon signature fails even when the new option is omitted:
        // framework gatherWorkerOptions() reads it unconditionally on startup.
        $this->assertSame(0, $command->run(new ArrayInput($arguments), new BufferedOutput));
    }
}
