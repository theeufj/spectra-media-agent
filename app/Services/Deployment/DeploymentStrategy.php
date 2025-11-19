<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Strategy;

interface DeploymentStrategy
{
    /**
     * Deploys a full campaign, including ad groups, ads, and assets.
     *
     * @param Campaign $campaign The local campaign model.
     * @param Strategy $strategy The specific strategy for this platform.
     * @return bool True on success, false on failure.
     */
    public function deploy(Campaign $campaign, Strategy $strategy): bool;
}
