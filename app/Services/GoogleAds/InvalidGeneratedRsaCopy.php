<?php

namespace App\Services\GoogleAds;

/** A replacement was never eligible for review or submission to Google. */
final class InvalidGeneratedRsaCopy extends \RuntimeException
{
    public function __construct(int $attempts, \Throwable $previous)
    {
        parent::__construct("Automatic ad-copy repair could not produce valid replacement text after {$attempts} attempts. "
            .$previous->getMessage().' The existing ad copy was not replaced.', previous: $previous);
    }
}
