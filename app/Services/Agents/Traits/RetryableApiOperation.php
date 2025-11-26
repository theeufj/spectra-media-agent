<?php

namespace App\Services\Agents\Traits;

use Illuminate\Support\Facades\Log;
use App\Services\CircuitBreaker\CircuitBreakerService;

/**
 * Trait RetryableApiOperation
 * 
 * Provides retry logic with exponential backoff for API operations.
 * Integrates with CircuitBreakerService for failure protection.
 * 
 * Recommended Improvements Implemented:
 * - Exponential backoff for rate limiting
 * - Circuit breaker integration
 * - Categorized error handling (retryable vs fatal)
 * - Detailed logging for debugging
 */
trait RetryableApiOperation
{
    /**
     * Default retry configuration
     */
    protected int $maxRetries = 3;
    protected int $initialDelayMs = 1000;
    protected float $backoffMultiplier = 2.0;
    protected int $maxDelayMs = 30000;
    
    /**
     * Execute an API operation with retry logic and circuit breaker protection.
     *
     * @param callable $operation The operation to execute
     * @param string $operationName Human-readable name for logging
     * @param array $context Additional context for logging
     * @param array $options Override default retry options
     * @return mixed Result of the operation
     * @throws \Exception When all retries are exhausted or fatal error occurs
     */
    protected function executeWithRetry(
        callable $operation,
        string $operationName,
        array $context = [],
        array $options = []
    ): mixed {
        $maxRetries = $options['maxRetries'] ?? $this->maxRetries;
        $initialDelay = $options['initialDelayMs'] ?? $this->initialDelayMs;
        $backoffMultiplier = $options['backoffMultiplier'] ?? $this->backoffMultiplier;
        $maxDelay = $options['maxDelayMs'] ?? $this->maxDelayMs;
        
        // Get circuit breaker for this service
        $circuitBreaker = $this->getCircuitBreaker($operationName);
        
        if (!$circuitBreaker->isAvailable()) {
            Log::warning("RetryableApiOperation: Circuit breaker open for {$operationName}", $context);
            throw new \Exception("Service {$operationName} is temporarily unavailable. Circuit breaker is open.");
        }
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                Log::debug("RetryableApiOperation: Executing {$operationName} (attempt {$attempt}/{$maxRetries})", $context);
                
                $result = $operation();
                
                // Success - record with circuit breaker and return
                $circuitBreaker->recordSuccess();
                
                Log::info("RetryableApiOperation: {$operationName} succeeded on attempt {$attempt}", array_merge($context, [
                    'attempts' => $attempt,
                ]));
                
                return $result;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                // Categorize the error
                $errorCategory = $this->categorizeError($e);
                
                Log::warning("RetryableApiOperation: {$operationName} failed on attempt {$attempt}/{$maxRetries}", array_merge($context, [
                    'error' => $e->getMessage(),
                    'error_category' => $errorCategory,
                    'exception_class' => get_class($e),
                ]));
                
                // Don't retry fatal errors
                if ($errorCategory === 'fatal') {
                    $circuitBreaker->recordFailure();
                    throw $e;
                }
                
                // Don't retry if we've exhausted attempts
                if ($attempt >= $maxRetries) {
                    $circuitBreaker->recordFailure();
                    break;
                }
                
                // Calculate delay with exponential backoff + jitter
                $delayMs = $this->calculateBackoffDelay($attempt, $initialDelay, $backoffMultiplier, $maxDelay);
                
                Log::info("RetryableApiOperation: Retrying {$operationName} in {$delayMs}ms", array_merge($context, [
                    'attempt' => $attempt,
                    'next_attempt' => $attempt + 1,
                ]));
                
                // Wait before retry
                usleep($delayMs * 1000); // Convert to microseconds
            }
        }
        
        // All retries exhausted
        Log::error("RetryableApiOperation: {$operationName} failed after {$maxRetries} attempts", array_merge($context, [
            'final_error' => $lastException?->getMessage(),
        ]));
        
        throw $lastException ?? new \Exception("Operation {$operationName} failed after {$maxRetries} attempts");
    }
    
    /**
     * Execute multiple API operations in sequence with individual retry logic.
     * Collects results and errors for batch processing.
     *
     * @param array $operations Array of ['name' => string, 'operation' => callable, 'context' => array]
     * @param bool $stopOnError Whether to stop on first error
     * @return array Results with 'success', 'results', 'errors' keys
     */
    protected function executeBatchWithRetry(array $operations, bool $stopOnError = false): array
    {
        $results = [];
        $errors = [];
        $success = true;
        
        foreach ($operations as $index => $op) {
            $name = $op['name'] ?? "operation_{$index}";
            $operation = $op['operation'];
            $context = $op['context'] ?? [];
            $options = $op['options'] ?? [];
            
            try {
                $results[$name] = $this->executeWithRetry($operation, $name, $context, $options);
            } catch (\Exception $e) {
                $success = false;
                $errors[$name] = $e->getMessage();
                
                if ($stopOnError) {
                    break;
                }
            }
        }
        
        return [
            'success' => $success,
            'results' => $results,
            'errors' => $errors,
        ];
    }
    
    /**
     * Calculate exponential backoff delay with jitter.
     *
     * @param int $attempt Current attempt number (1-indexed)
     * @param int $initialDelay Initial delay in milliseconds
     * @param float $multiplier Backoff multiplier
     * @param int $maxDelay Maximum delay in milliseconds
     * @return int Delay in milliseconds
     */
    protected function calculateBackoffDelay(
        int $attempt,
        int $initialDelay,
        float $multiplier,
        int $maxDelay
    ): int {
        // Calculate base delay with exponential backoff
        $baseDelay = $initialDelay * pow($multiplier, $attempt - 1);
        
        // Add jitter (±25% of base delay)
        $jitter = $baseDelay * 0.25 * (mt_rand() / mt_getrandmax() * 2 - 1);
        $delay = (int) ($baseDelay + $jitter);
        
        // Clamp to max delay
        return min($delay, $maxDelay);
    }
    
    /**
     * Categorize an error as retryable or fatal.
     *
     * @param \Exception $e The exception to categorize
     * @return string 'retryable' or 'fatal'
     */
    protected function categorizeError(\Exception $e): string
    {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();
        
        // Fatal errors - don't retry
        $fatalPatterns = [
            'invalid credentials',
            'authentication failed',
            'authorization failed',
            'unauthorized',
            'forbidden',
            'not found',
            'invalid parameter',
            'invalid argument',
            'invalid value',
            'policy violation',
            'billing',
            'budget constraint',
            'duplicate',
            'already exists',
            'permission denied',
        ];
        
        foreach ($fatalPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return 'fatal';
            }
        }
        
        // HTTP status codes
        if ($code >= 400 && $code < 500 && $code !== 429 && $code !== 408) {
            return 'fatal'; // Client errors (except rate limit and timeout)
        }
        
        // Retryable by default for network/server errors
        return 'retryable';
    }
    
    /**
     * Get or create a circuit breaker for an operation.
     *
     * @param string $operationName Operation name for circuit breaker key
     * @return CircuitBreakerService
     */
    protected function getCircuitBreaker(string $operationName): CircuitBreakerService
    {
        $platform = $this->platform ?? 'unknown';
        $serviceName = "{$platform}_{$operationName}";
        
        return new CircuitBreakerService(
            serviceName: $serviceName,
            maxFailures: 5,
            retryTimeout: 300 // 5 minutes
        );
    }
    
    /**
     * Check if an operation is rate limited based on response.
     *
     * @param mixed $response API response
     * @return bool True if rate limited
     */
    protected function isRateLimited(mixed $response): bool
    {
        if (is_array($response)) {
            $errorCode = $response['error']['code'] ?? null;
            $errorMessage = strtolower($response['error']['message'] ?? '');
            
            return $errorCode === 429 || 
                   str_contains($errorMessage, 'rate limit') ||
                   str_contains($errorMessage, 'quota exceeded') ||
                   str_contains($errorMessage, 'too many requests');
        }
        
        return false;
    }
}
