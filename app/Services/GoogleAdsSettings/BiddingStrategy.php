<?php

namespace App\Services\GoogleAdsSettings;

interface BiddingStrategy
{
    /**
     * Returns the bidding strategy configuration as an array.
     */
    public function getConfiguration(): array;
}
