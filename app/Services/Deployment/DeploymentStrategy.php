<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Connection;
use App\Models\Strategy;

interface DeploymentStrategy
{
    /**
     * Deploys a full campaign, including ad groups, ads, and assets.
     *
     * @param Campaign $campaign The local campaign model.
     * @param Strategy $strategy The specific strategy for this platform.
     * @param Connection $connection The user's connection details for this platform.
     * @return bool True on success, false on failure.
     */
    public function deploy(Campaign $campaign, Strategy $strategy, Connection $connection): bool;
}
