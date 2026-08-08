<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Capture an exception for operators and hand back a reference for the user.
 *
 * Raw exception text was being returned straight to customers. That leaks
 * internals with no upside to them — the live example was a Vertex AI 403 whose
 * body contained the GCP project ID and a billing-console URL, which a customer
 * would have seen verbatim on a failed action.
 *
 * The reference is the point: the user gets something short they can quote to
 * support, and the same string is on the log line and the reported exception, so
 * it can be found without asking them to reproduce anything.
 */
class SafeError
{
    /**
     * Log + report the exception, returning a short reference to show the user.
     */
    public static function capture(\Throwable $e, string $summary, array $context = []): string
    {
        $ref = strtoupper(Str::random(6));

        Log::error($summary.' ['.$ref.']', array_merge($context, [
            'ref' => $ref,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]));

        // Surface in the admin exception dashboard too.
        report($e);

        return $ref;
    }

    /**
     * Capture and build the user-facing sentence in one step.
     *
     * @param  string  $userMessage  Plain-language, no internals. e.g.
     *                               "We couldn't update your payment method."
     */
    public static function message(\Throwable $e, string $userMessage, array $context = []): string
    {
        $ref = self::capture($e, $userMessage, $context);

        return $userMessage.' Please try again, or contact support with reference '.$ref.'.';
    }
}
