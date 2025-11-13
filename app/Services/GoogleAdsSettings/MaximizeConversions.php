<?php

namespace App\Services\GoogleAdsSettings;

class MaximizeConversions implements BiddingStrategy
{
    protected $targetCpaMicros;

    public function __construct(int $targetCpaMicros = null)
    {
        $this->targetCpaMicros = $targetCpaMicros;
    }

    public function getConfiguration(): array
    {
        $config = ['maximizeConversions' => new \stdClass()];

        if ($this->targetCpaMicros) {
            $config['maximizeConversions']->targetCpaMicros = $this->targetCpaMicros;
        }

        return $config;
    }
}
