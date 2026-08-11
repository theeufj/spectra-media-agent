<?php

namespace App\Contracts\Ads;

/**
 * Meta insights reads.
 *
 * Grouped by role rather than mirroring the existing service split: both
 * CreativeIntelligenceAgent and SelfHealingAgent want "numbers out of Meta",
 * and which class they happen to live on is an implementation detail.
 */
interface FacebookInsightSource
{
    public function getAccountInsightsByLevel(
        string $accountId,
        string $dateStart,
        string $dateEnd,
        string $level = 'account',
        ?array $fields = null,
        int $limit = 100
    ): array;

    public function getCampaignInsights(string $campaignId, string $dateStart, string $dateEnd, ?array $fields = null): ?array;

    /**
     * Extract a single action total (purchases, leads…) from an insights row.
     * Pure parsing, no I/O — the sandbox reuses the live implementation.
     */
    public function parseAction(?array $actions, string $actionType = 'purchase'): float;
}
