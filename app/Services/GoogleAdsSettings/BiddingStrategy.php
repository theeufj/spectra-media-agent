<?php

namespace App\Services\GoogleAdsSettings;

interface BiddingStrategy
{
    /**
     * Returns the bidding strategy configuration as an array.
     *
     * @return array
     */
    public function getConfiguration(): array;
}
