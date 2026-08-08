<?php

namespace Tests\Unit\Mail;

use App\Mail\DailyPerformanceReport;
use App\Mail\MonthlyExecutiveReport;
use App\Mail\WeeklyExecutiveReport;
use App\Models\User;
use Illuminate\Queue\Middleware\RateLimited;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mailables carrying the RateLimited middleware must not run on a tries:1 queue.
 *
 * RateLimited *releases* a job when the limit is hit, and a release counts as an
 * attempt. The 'default' Horizon supervisor runs tries:1, so the first
 * rate-limited send exceeded its budget and died with
 * MaxAttemptsExceededException — which is exactly what happened to
 * DailyPerformanceReport in production on 2026-08-05.
 *
 * These assert the three properties that together make that impossible:
 * the rate-limited queue, a deadline instead of an attempt budget, and a
 * spread-out delay so a bulk send doesn't hit the limiter all at once.
 */
class RateLimitedMailQueueingTest extends TestCase
{
    /** @return array<string, array{0: \Illuminate\Mail\Mailable}> */
    public static function rateLimitedMailables(): array
    {
        $user = new User(['email' => 'someone@example.com']);

        return [
            'daily performance report' => [new DailyPerformanceReport($user, ['date' => '2026-08-07'])],
            'weekly executive report' => [new WeeklyExecutiveReport($user, [])],
            'monthly executive report' => [new MonthlyExecutiveReport($user, [])],
        ];
    }

    #[DataProvider('rateLimitedMailables')]
    public function test_rate_limited_mail_does_not_use_the_tries_one_queue($mailable): void
    {
        $usesRateLimiter = collect($mailable->middleware())
            ->contains(fn ($m) => $m instanceof RateLimited);

        $this->assertTrue($usesRateLimiter, 'fixture should be a rate-limited mailable');

        // 'default' is the tries:1 supervisor — a single release kills the job.
        $this->assertNotSame('default', $mailable->queue);
        $this->assertSame('notifications', $mailable->queue);
    }

    #[DataProvider('rateLimitedMailables')]
    public function test_rate_limited_mail_retries_on_a_deadline_not_an_attempt_count($mailable): void
    {
        $this->assertTrue(
            method_exists($mailable, 'retryUntil'),
            'a rate-limited mailable needs retryUntil(), or releases consume its $tries budget'
        );

        $this->assertGreaterThan(now(), $mailable->retryUntil());
    }

    #[DataProvider('rateLimitedMailables')]
    public function test_rate_limited_mail_is_spread_out($mailable): void
    {
        // Queued one-per-user in a loop; without a delay they all arrive in the
        // same second and everything past the limiter's 4/sec is released.
        $this->assertNotNull($mailable->delay, 'bulk mail should be spread to avoid a thundering herd');
    }
}
