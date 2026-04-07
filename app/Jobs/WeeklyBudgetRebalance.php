<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\PlatformBudgetAllocation;
use App\Services\CrossChannelBudgetAllocator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WeeklyBudgetRebalance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $allocations = PlatformBudgetAllocation::where('auto_rebalance', true)->get();

        foreach ($allocations as $allocation) {
            try {
                $customer = $allocation->customer;
                if (!$customer) continue;

                $frequency = $allocation->rebalance_frequency;
                $last = $allocation->last_rebalanced_at;

                // Check if it's time to rebalance
                $shouldRun = match ($frequency) {
                    'daily' => !$last || $last->diffInHours(now()) >= 20,
                    'weekly' => !$last || $last->diffInDays(now()) >= 6,
                    'monthly' => !$last || $last->diffInDays(now()) >= 28,
                    default => false,
                };

                if (!$shouldRun) continue;

                $allocator = new CrossChannelBudgetAllocator();
                $result = $allocator->rebalance($customer, 'scheduled');

                Log::info('WeeklyBudgetRebalance: Processed', [
                    'customer_id' => $customer->id,
                    'status' => $result['status'] ?? 'unknown',
                ]);
            } catch (\Exception $e) {
                Log::error('WeeklyBudgetRebalance: Failed for customer', [
                    'customer_id' => $allocation->customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('WeeklyBudgetRebalance failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
