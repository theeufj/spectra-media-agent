<?php

namespace App\Jobs\Scheduled;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Alert admins before OpenRouter credit runs out and creative generation
 * silently falls back to the Gemini/Veo stack.
 *
 * Was a Schedule::call() closure, and the worst of them: it made a live HTTP
 * call to openrouter.ai inside the scheduler tick, with no timeout of its own
 * and no retry. A slow response there stalls the whole tick.
 */
class OpenRouterLowCreditCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        $credits = app(\App\Services\OpenRouterService::class)->creditBalance();
        $threshold = (float) config('services.openrouter.low_credit_alert', 10);

        if ($credits && $credits['remaining'] < $threshold) {
            \App\Notifications\CriticalAgentAlert::deliver(
                'openrouter_credits_low',
                sprintf('OpenRouter credits low: $%.2f remaining', $credits['remaining']),
                sprintf('Creative generation (Grok images + video) runs on these credits — at $0 it falls back to Gemini/Veo. $%.2f used of $%.2f. Top up at openrouter.ai.', $credits['used'], $credits['total']),
                $credits,
                \App\Models\NotificationTemplate::RECIPIENTS_ADMINS
            );
        }
    }
}
