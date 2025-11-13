<?php

namespace App\Services\GoogleAdsSettings;

class TargetCpa implements BiddingStrategy
{
    protected $targetCpaMicros;

    public function __construct(int $targetCpaMicros)
    {
        $this->targetCpaMicros = $targetCpaMicros;
    }

    public function getConfiguration(): array
    {
        return [
            'targetCpa' => [
                'targetCpaMicros' => $this->targetCpaMicros,
            ],
        ];
    }
}
