<?php

namespace App\Services\GoogleAds;

final class RsaCopyReviewRejected extends \RuntimeException
{
    public function __construct(int $candidates)
    {
        parent::__construct("Automatic ad-copy repair did not produce an approved replacement within {$candidates} candidates. Review feedback was used for correction, but the existing ad copy was not replaced.");
    }
}
