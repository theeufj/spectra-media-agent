<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\SupportTicket;
use App\Services\Support\SupportChatAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Watches what the support assistant is actually telling customers.
 *
 * The assistant answers paying customers with no human in the loop, and the two
 * ways it can go wrong are both invisible in the reply text on its own:
 *
 *  1. It answers an account question from the prompt alone, without consulting
 *     the account — generic advice dressed as specific advice.
 *  2. It quotes a figure that came from nowhere.
 *
 * Both are checkable after the fact because the transcript stores the tool calls
 * and their outputs alongside each reply. This job does that check nightly and
 * leaves an AgentRun trace, so it shows up on /admin/automation-health next to
 * every other scheduled agent.
 *
 * WARNINGS, NOT ERRORS. Unsourced figures are a heuristic — a model rounding
 * $1,234.56 to "about $1,200" trips it and is perfectly fine. Reporting those as
 * failures would train everyone to ignore this job within a fortnight, which is
 * the only real failure mode a monitor has. `errors` is reserved for the case
 * that is genuinely provable: an account question answered with no tool call.
 */
class MonitorSupportChatQuality implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    /** How far back each run looks. Comfortably longer than the daily cadence. */
    private const LOOKBACK_DAYS = 2;

    public function handle(SupportChatAudit $audit): void
    {
        $started = $this->startRun();

        $ticketsReviewed = 0;
        $repliesReviewed = 0;
        $answeredBlind = 0;      // should have consulted the account, did not
        $withUnsourced = 0;      // quoted a figure traceable to nothing
        $toolBacked = 0;
        $flagged = [];

        SupportTicket::where('source', 'chatbot')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereNotNull('transcript')
            ->chunkById(100, function ($tickets) use (
                $audit, &$ticketsReviewed, &$repliesReviewed,
                &$answeredBlind, &$withUnsourced, &$toolBacked, &$flagged
            ) {
                foreach ($tickets as $ticket) {
                    // Per ticket, so one malformed transcript cannot abort the
                    // whole review.
                    try {
                        $ticketsReviewed++;
                        $turns = $ticket->transcript ?? [];

                        foreach ($turns as $i => $turn) {
                            if (($turn['role'] ?? null) !== 'assistant') {
                                continue;
                            }

                            $repliesReviewed++;

                            $question = $turns[$i - 1]['text'] ?? '';
                            $reply = $turn['text'] ?? '';
                            $toolsUsed = $turn['tools_used'] ?? [];
                            $toolNumbers = $turn['tool_numbers'] ?? [];

                            if ($toolsUsed !== []) {
                                $toolBacked++;
                            }

                            // Only meaningful for accounts that HAVE data to
                            // consult; a user with no customer gets no tools by
                            // design and must not be counted against the model.
                            if ($ticket->customer_id && $toolsUsed === [] && $audit->shouldHaveUsedTools($question)) {
                                $answeredBlind++;
                                $flagged[] = [
                                    'ticket_id' => $ticket->id,
                                    'issue' => 'answered_without_consulting_account',
                                    'question' => \Illuminate\Support\Str::limit($question, 120),
                                ];
                            }

                            $unsourced = $audit->unsourcedFigures($reply, $toolNumbers, $question);

                            if ($unsourced !== []) {
                                $withUnsourced++;
                                $flagged[] = [
                                    'ticket_id' => $ticket->id,
                                    'issue' => 'figures_not_traceable_to_a_tool',
                                    'figures' => array_slice($unsourced, 0, 5),
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        // report() reaches the admin exception dashboard;
                        // Log::error alone does not. Keep the batch running.
                        report($e);
                        Log::error('Support chat quality review failed for a ticket', [
                            'ticket_id' => $ticket->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $toolRate = $repliesReviewed > 0 ? round($toolBacked / $repliesReviewed * 100, 1) : 0.0;

        $this->finishRun(
            $started,
            // "Actions" is replies reviewed: a run that reviewed nothing is a
            // no_op, which is exactly what should show if the widget went quiet
            // or stopped recording.
            actions: $repliesReviewed,
            errors: $answeredBlind,
            warnings: $withUnsourced,
            scope: "{$ticketsReviewed} chat tickets, {$repliesReviewed} replies",
            note: $this->summarise($repliesReviewed, $toolRate, $answeredBlind, $withUnsourced),
            details: [
                'tickets_reviewed' => $ticketsReviewed,
                'replies_reviewed' => $repliesReviewed,
                'tool_backed_replies' => $toolBacked,
                'tool_use_rate_pct' => $toolRate,
                'answered_without_consulting_account' => $answeredBlind,
                'replies_with_unsourced_figures' => $withUnsourced,
                // Capped: this lands in a jsonb column read by a dashboard, not
                // an archive. The counts above are the metric; these are leads.
                'flagged' => array_slice($flagged, 0, 25),
            ],
        );
    }

    private function summarise(int $replies, float $toolRate, int $blind, int $unsourced): string
    {
        if ($replies === 0) {
            return 'No support chat replies in the window — nothing to review.';
        }

        return sprintf(
            '%d replies reviewed. %.1f%% consulted the account. %d answered an account question without '
            .'looking. %d quoted figures not traceable to a tool (review, not necessarily wrong).',
            $replies, $toolRate, $blind, $unsourced,
        );
    }

    public function failed(\Throwable $e): void
    {
        $this->recordRunFailure($e);
    }
}
