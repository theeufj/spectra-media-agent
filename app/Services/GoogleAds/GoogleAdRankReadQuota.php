<?php

namespace App\Services\GoogleAds;

use Google\ApiCore\ApiException;

/** A confirmed quota rejection from a read-only Google Ads operation. */
class GoogleAdRankReadQuota extends \RuntimeException
{
    public function __construct(public readonly string $source, public readonly int $retryAfterSeconds, ApiException $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
