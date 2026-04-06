<?php

namespace App\Services\Crm;

interface CrmConnectorInterface
{
    /**
     * Test the connection to the CRM.
     */
    public function testConnection(): bool;

    /**
     * Fetch leads/deals that have been won/closed since the given timestamp.
     * Returns array of normalized lead records.
     */
    public function fetchClosedLeads(\DateTimeInterface $since): array;

    /**
     * Get available pipeline stages for mapping.
     */
    public function getPipelineStages(): array;

    /**
     * Get the provider name.
     */
    public function getProvider(): string;
}
