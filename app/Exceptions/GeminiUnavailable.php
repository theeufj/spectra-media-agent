<?php

namespace App\Exceptions;

/**
 * Every route to the model failed — primary and fallback, retries exhausted.
 *
 * Raised to be reported, never to be caught: GeminiService still returns null
 * so callers behave as before. It exists because a total AI outage was
 * invisible. On 2026-09-12 every text call returned 403 BILLING_DISABLED for a
 * day and more — strategy generation, brand extraction, creative, the copilot
 * and the public demo all dead — and the only trace was Log::error in a dated
 * file. `runtime_exceptions` had nothing, so the admin dashboard showed a
 * healthy product. It was found from a screenshot of the demo writing no ad
 * copy.
 *
 * CLAUDE.md has the rule this broke: Log::error() alone does not reach the
 * admin exception dashboard, and in a path that matters you call report() too.
 * GeminiService is the path that matters most — nothing else in the product
 * works without it.
 */
class GeminiUnavailable extends \RuntimeException {}
