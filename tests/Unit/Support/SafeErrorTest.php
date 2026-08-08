<?php

namespace Tests\Unit\Support;

use App\Support\SafeError;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * User-facing copy must never carry exception internals.
 *
 * The case that prompted this: a Vertex AI 403 whose body contained the GCP
 * project ID and a billing-console URL. Returned verbatim, a customer would have
 * seen all of it on a failed action.
 */
class SafeErrorTest extends TestCase
{
    private function vertexBillingError(): \Exception
    {
        return new \Exception(
            'This API method requires billing to be enabled. Please enable billing on '
            .'project #halogen-plasma-487509-e3 by visiting '
            .'https://console.developers.google.com/billing/enable?project=halogen-plasma-487509-e3'
        );
    }

    public function test_user_message_excludes_exception_internals(): void
    {
        $message = SafeError::message($this->vertexBillingError(), "We couldn't generate ad copy.");

        $this->assertStringNotContainsString('halogen-plasma', $message);
        $this->assertStringNotContainsString('console.developers.google.com', $message);
        $this->assertStringNotContainsString('billing to be enabled', $message);
        $this->assertStringContainsString("We couldn't generate ad copy.", $message);
    }

    public function test_user_message_carries_a_reference(): void
    {
        $message = SafeError::message($this->vertexBillingError(), 'Something went wrong.');

        $this->assertMatchesRegularExpression('/reference [A-Z0-9]{6}\.$/', $message);
    }

    public function test_full_detail_still_reaches_the_log(): void
    {
        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $ref = SafeError::capture($this->vertexBillingError(), 'Ad copy generation failed', ['campaign_id' => 31]);

        $this->assertNotEmpty($captured, 'expected the exception to be logged');
        $entry = $captured[0];

        $this->assertStringContainsString($ref, $entry->message);
        $this->assertSame($ref, $entry->context['ref']);
        $this->assertSame(31, $entry->context['campaign_id']);
        // The detail the user must not see still has to reach operators.
        $this->assertStringContainsString('halogen-plasma', $entry->context['message']);
    }

    public function test_each_capture_gets_a_distinct_reference(): void
    {
        $a = SafeError::capture(new \Exception('x'), 'first');
        $b = SafeError::capture(new \Exception('y'), 'second');

        $this->assertNotSame($a, $b);
    }
}
