<?php

namespace App\Services\Testing;

class ChaosEngineeringService
{
    public function __invoke(): void
    {
        // This is a very basic example. A real implementation would
        // use a more sophisticated chaos engineering framework.
        if (rand(1, 10) === 1) {
            throw new \Exception('Chaos monkey strikes!');
        }
    }
}
