<?php

namespace App\Exceptions;

/**
 * The extraction did not work this time, and trying again might.
 *
 * BrandGuidelineExtractorService reported every outcome the same way — by
 * returning null — and the two kinds of null are nothing alike. "This customer
 * has no crawled content" and "the model returned a 429 just now" both produced
 * a null, and ExtractBrandGuidelines treated both as final: it mailed the
 * customer "we couldn't finish scanning your website" and stopped.
 *
 * That is what happened to customer 44. Four pages had been crawled with 13,800
 * characters of perfectly good content, the extractor returned null once, and
 * onboarding dead-ended. Running the exact same call again scored 96 out of
 * 100. Nothing about the site was the problem; the customer was simply told it
 * was, and was left with a manual-entry form as their only way forward.
 *
 * A throw is the difference. The job already has maxExceptions = 3 and an
 * hour's retryUntil, and none of it ever engaged, because a returned null is
 * not an exception and the queue had nothing to catch. Genuine dead ends — no
 * content, allowance exhausted — still return null and still stop immediately,
 * because retrying those really would be pointless.
 */
class BrandExtractionFailed extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Brand guideline extraction failed: {$reason}");
    }
}
