<?php

namespace Tests\Feature;

use App\Services\Campaigns\StrategyDocument;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StrategyCampaignTypeTest extends TestCase
{
    private function strategy(array $overrides = []): array
    {
        return array_replace([
            'platform' => 'Google Ads (SEM)',
            'ad_copy_strategy' => 'Listing-specific campaign management for real estate agents.',
            'imagery_strategy' => 'An agent reviewing property photographs.',
            'video_strategy' => 'No video for Search.',
            'generate_video' => true,
            'bidding_strategy' => ['name' => 'MaximizeClicks'],
            'revenue_cpa_multiple' => 2,
        ], $overrides);
    }

    public function test_legacy_sem_and_plain_google_responses_become_search_not_the_database_display_default(): void
    {
        foreach (['Google Ads (SEM)', 'Google Ads'] as $platform) {
            $document = StrategyDocument::fromArray(['strategies' => [$this->strategy(['platform' => $platform])]], ['Google Ads'], 50);
            $this->assertSame('search', $document->strategies[0]['campaign_type']);
            $this->assertFalse($document->strategies[0]['generate_video']);
        }
    }

    public function test_explicit_display_and_performance_max_choices_are_preserved(): void
    {
        foreach (['display', 'performance_max'] as $type) {
            $document = StrategyDocument::fromArray(['strategies' => [$this->strategy([
                'platform' => 'Google Ads', 'campaign_type' => $type,
            ])]], ['Google Ads'], 50);
            $this->assertSame($type, $document->strategies[0]['campaign_type']);
        }
    }

    public function test_a_conflicting_search_label_and_display_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        StrategyDocument::fromArray(['strategies' => [$this->strategy(['campaign_type' => 'display'])]], ['Google Ads'], 50);
    }
}
