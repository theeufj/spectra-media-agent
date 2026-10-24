<?php

namespace App\Services\GoogleAds;

final class RsaCopyReviewUnavailable extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The independent ad-copy review was unavailable or incomplete. The existing ad copy was not replaced.', previous: $previous);
    }
}
