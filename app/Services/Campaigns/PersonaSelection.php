<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Persona;

class PersonaSelection
{
    /**
     * Campaign-specific personas take precedence over account-wide personas.
     * Never use an inactive persona or one belonging to another campaign or tenant.
     */
    public function forCampaign(Campaign $campaign, ?int $requestedId = null): ?Persona
    {
        $eligible = Persona::query()
            ->where('customer_id', $campaign->customer_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('campaign_id', $campaign->id)->orWhereNull('campaign_id'));

        if ($requestedId !== null) {
            return (clone $eligible)->whereKey($requestedId)->first();
        }

        return (clone $eligible)->where('campaign_id', $campaign->id)->latest()->first()
            ?? (clone $eligible)->whereNull('campaign_id')->latest()->first();
    }
}
