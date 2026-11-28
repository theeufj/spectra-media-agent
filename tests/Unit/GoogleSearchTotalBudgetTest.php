<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\GoogleSearchTotalBudget;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleSearchTotalBudgetTest extends TestCase
{
    private function strategy(): Strategy
    {
        $cap = ['total_micros' => 154000000, 'currency' => 'AUD', 'start_date' => '2026-11-28', 'end_date' => '2026-12-04'];
        $campaign = new Campaign(['start_date' => $cap['start_date'], 'end_date' => $cap['end_date'],
            'total_budget' => '154.00', 'daily_budget' => '22.00', 'platforms' => ['google'],
            'spend_guardrails' => ['google_total_budget' => $cap]]);
        $campaign->setRelation('customer', new Customer(['currency_code' => 'AUD']));

        return (new Strategy(['platform' => 'Google Ads (SEM)', 'campaign_type' => 'search', 'daily_budget' => '22.00',
            'bidding_strategy' => ['google_total_budget' => $cap]]))->setRelation('campaign', $campaign);
    }

    public function test_reads_only_matching_fixed_budget_and_preserves_daily_campaigns(): void
    {
        $strategy = $this->strategy();
        $this->assertSame(154000000, GoogleSearchTotalBudget::forStrategy($strategy)['total_micros']);
        $strategy->campaign->spend_guardrails = [];
        $strategy->bidding_strategy = [];
        $this->assertNull(GoogleSearchTotalBudget::forStrategy($strategy));
    }

    public static function changedSettings(): array
    {
        return [['daily_budget', '25.00'], ['total_budget', '170.00'], ['end_date', '2026-12-05'],
            ['platforms', ['google', 'facebook']], ['spend_guardrails', []]];
    }

    #[DataProvider('changedSettings')]
    public function test_rejects_a_change_to_the_fixed_campaign(string $field, mixed $value): void
    {
        $strategy = $this->strategy();
        $strategy->campaign->setAttribute($field, $value);
        $this->expectException(ValidationException::class);
        GoogleSearchTotalBudget::forStrategy($strategy);
    }

    public function test_strategy_regeneration_cannot_silently_fall_back_to_a_daily_budget(): void
    {
        $strategy = $this->strategy();
        $strategy->bidding_strategy = [];
        $this->expectException(ValidationException::class);
        GoogleSearchTotalBudget::forStrategy($strategy);
    }
}
