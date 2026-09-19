<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/** Spaces image requests across workers, including reference images and retries. */
class GeminiImagePacer
{
    private function key(string $model): string
    {
        return 'gemini:image-pacing:'.hash('sha256', config('services.google.project_id').':'.$model);
    }

    public function awaitTurn(string $model): bool
    {
        $key = $this->key($model);
        $deadline = now()->getTimestampMs() + max(0, (int) config('ai.gemini_images.max_wait_seconds')) * 1000;

        do {
            // Only hold the lock while claiming a start time, never while sleeping
            // or calling Google. Waiting workers recheck any new shared cooldown.
            $waitMs = Cache::lock($key.':lock', 10)->get(function () use ($key): int {
                $now = now()->getTimestampMs();
                $next = (int) Cache::get($key.':next', 0);

                if ($next > $now) {
                    return $next - $now;
                }

                $interval = max(0, (int) config('ai.gemini_images.interval_seconds'));
                Cache::put($key.':next', $now + $interval * 1000, max(60, $interval + 60));

                return 0;
            });

            if ($waitMs === 0) {
                return true;
            }

            $waitMs = $waitMs === false ? 100 : $waitMs;
            if (now()->getTimestampMs() + $waitMs > $deadline) {
                return false;
            }

            Sleep::for($waitMs)->milliseconds();
        } while (now()->getTimestampMs() <= $deadline);

        return false;
    }

    public function coolDown(string $model, Response $response): void
    {
        $key = $this->key($model);
        $retryAfter = $response->header('Retry-After');
        $seconds = is_numeric($retryAfter)
            ? (float) $retryAfter
            : max(0, (strtotime($retryAfter) ?: 0) - now()->timestamp);

        // Vertex may send RetryInfo in either a normal error object or a stream.
        $body = $response->json();
        foreach (data_get($body, 'error.details', data_get($body, '0.error.details', [])) as $detail) {
            if (preg_match('/^(\d+(?:\.\d+)?)s$/', $detail['retryDelay'] ?? '', $match)) {
                $seconds = max($seconds, (float) $match[1]);
            }
        }

        Cache::lock($key.':lock', 10)->block(5, function () use ($key, $seconds): void {
            $failures = min(4, (int) Cache::get($key.':failures', 0) + 1);
            $backoff = min(300, max(1, (int) config('ai.gemini_images.cooldown_seconds')) * 2 ** ($failures - 1));
            $delay = (int) ceil(max($seconds, $backoff));
            $next = max((int) Cache::get($key.':next', 0), now()->getTimestampMs() + $delay * 1000);

            Cache::put($key.':next', $next, (int) ceil(($next - now()->getTimestampMs()) / 1000) + 60);
            Cache::put($key.':failures', $failures, 600);
        });
    }
}
