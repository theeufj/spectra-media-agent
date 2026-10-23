<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;

/** Platform providers may be tagged campaign.market_research.providers in the container. */
interface CampaignMarketResearchProvider
{
    public function supports(Strategy $strategy): bool;

    /** Return the same strategy proposal shape; never write local or platform settings. */
    public function propose(Campaign $campaign, Strategy $strategy, array $businessEvidence, array $inputs): array;
}
