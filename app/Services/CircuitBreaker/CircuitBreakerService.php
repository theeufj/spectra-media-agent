<?php

namespace App\Services\CircuitBreaker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    private $serviceName;
    private $maxFailures;
    private $retryTimeout; // in seconds

    public function __construct(string $serviceName, int $maxFailures = 3, int $retryTimeout = 60)
    {
        $this->serviceName = $serviceName;
        $this->maxFailures = $maxFailures;
        $this->retryTimeout = $retryTimeout;
    }

    public function isAvailable(): bool
    {
        if ($this->isTripped()) {
            if ($this->isRetryTimeoutExpired()) {
                $this->reset();
                return true;
            }
            return false;
        }
        return true;
    }

    public function recordFailure(): void
    {
        $failures = $this->getFailures() + 1;
        Cache::put($this->getFailuresCacheKey(), $failures, $this->retryTimeout * 2);

        if ($failures >= $this->maxFailures) {
            $this->trip();
        }
    }

    public function recordSuccess(): void
    {
        $this->reset();
    }

    private function isTripped(): bool
    {
        return Cache::has($this->getTrippedCacheKey());
    }

    private function isRetryTimeoutExpired(): bool
    {
        $trippedTime = Cache::get($this->getTrippedCacheKey());
        return (time() - $trippedTime) >= $this->retryTimeout;
    }

    private function trip(): void
    {
        Cache::put($this->getTrippedCacheKey(), time(), $this->retryTimeout * 2);
        Log::warning("Circuit breaker for {$this->serviceName} has been tripped.");
    }

    private function reset(): void
    {
        Cache::forget($this->getFailuresCacheKey());
        Cache::forget($this->getTrippedCacheKey());
    }

    private function getFailures(): int
    {
        return Cache::get($this->getFailuresCacheKey(), 0);
    }

    private function getFailuresCacheKey(): string
    {
        return "circuit-breaker:{$this->serviceName}:failures";
    }

    private function getTrippedCacheKey(): string
    {
        return "circuit-breaker:{$this->serviceName}:tripped";
    }
}
