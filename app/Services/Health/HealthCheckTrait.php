<?php

namespace App\Services\Health;

trait HealthCheckTrait
{
    protected function determineHealthStatus(array $issues, array $warnings): string
    {
        $hasCritical = false;
        $hasHigh     = false;

        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'critical') {
                $hasCritical = true;
                break;
            }
            if (($issue['severity'] ?? '') === 'high') {
                $hasHigh = true;
            }
        }

        if ($hasCritical) return 'critical';
        if ($hasHigh || !empty($issues)) return 'unhealthy';
        if (!empty($warnings)) return 'warning';

        return 'healthy';
    }
}
