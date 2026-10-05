<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Server-side run state survives navigation and does not expose provider errors. */
class WorkStatus
{
    public static function start(int $customerId, string $task, array $context = []): ?string
    {
        return Cache::lock(self::key($customerId, $task).':dispatch', 10)->get(function () use ($customerId, $task, $context) {
            $previous = self::get($customerId, $task);
            if (in_array($previous['status'] ?? null, ['queued', 'running'], true)) {
                return null;
            }
            $id = (string) Str::uuid();
            Cache::put(self::key($customerId, $task), [
                'id' => $id, 'status' => 'queued', 'updated_at' => now()->toIso8601String(), 'message' => 'Waiting for a worker.', 'context' => $context,
            ], now()->addDays(2));

            return $id;
        });
    }

    public static function update(int $customerId, string $task, ?string $id, string $status, ?string $message = null): void
    {
        if ($id === null) {
            return;
        }
        Cache::lock(self::key($customerId, $task).':dispatch', 10)->get(function () use ($customerId, $task, $id, $status, $message) {
            $current = Cache::get(self::key($customerId, $task));
            if (($current['id'] ?? null) !== $id) {
                return;
            }
            Cache::put(self::key($customerId, $task), [
                'id' => $id, 'status' => $status, 'updated_at' => now()->toIso8601String(), 'message' => $message, 'context' => $current['context'] ?? [],
            ], now()->addDays(2));
        });
    }

    /** @return array<string, mixed>|null */
    public static function get(int $customerId, string $task): ?array
    {
        $run = Cache::get(self::key($customerId, $task));
        if (! is_array($run)) {
            return null;
        }
        if (in_array($run['status'] ?? null, ['queued', 'running'], true)
            && \Carbon\CarbonImmutable::parse($run['updated_at'])->lt(now()->subMinutes(30))) {
            $run['status'] = 'failed';
            $run['message'] = 'This run stopped updating. You can retry the original request.';
        }

        return $run;
    }

    private static function key(int $customerId, string $task): string
    {
        return "customer-work:{$customerId}:{$task}";
    }
}
