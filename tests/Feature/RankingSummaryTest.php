<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SeoRanking;
use App\Services\SEO\RankTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unmeasured is not the same as ranking first.
 *
 * getSummary() bucketed positions straight off the collection, and in PHP
 * `null <= 10` is true. Every keyword the tracker failed to measure was
 * therefore counted as a page-one ranking. With Firecrawl returning 402 and all
 * 50 positions null, the dashboard displayed "Top 10 Rankings: 50" — while
 * Search Console put the real figure at zero.
 *
 * A customer-facing metric claiming fifty page-one rankings where there are none
 * is worse than showing nothing at all.
 */
class RankingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function ranking(Customer $customer, string $keyword, ?int $position): void
    {
        SeoRanking::create([
            'customer_id' => $customer->id,
            'keyword' => $keyword,
            'domain' => 'example.com',
            'date' => now()->toDateString(),
            'position' => $position,
            'search_engine' => 'google',
        ]);
    }

    public function test_unmeasured_keywords_are_never_counted_as_ranking(): void
    {
        $customer = Customer::factory()->create();

        foreach (range(1, 50) as $i) {
            $this->ranking($customer, "keyword {$i}", null);
        }

        $summary = (new RankTrackingService($customer))->getSummary();

        $this->assertSame(0, $summary['top_10_count'], 'null positions must not count as page one');
        $this->assertSame(0, $summary['top_3_count']);
        $this->assertSame(50, $summary['not_ranking']);
        $this->assertSame(50, $summary['total_keywords']);
        $this->assertSame(0, $summary['ranked_keywords']);
    }

    public function test_real_positions_are_bucketed_correctly(): void
    {
        $customer = Customer::factory()->create();

        $this->ranking($customer, 'ranks second', 2);
        $this->ranking($customer, 'ranks eighth', 8);
        $this->ranking($customer, 'ranks twenty-fifth', 25);
        $this->ranking($customer, 'not measured', null);

        $summary = (new RankTrackingService($customer))->getSummary();

        $this->assertSame(1, $summary['top_3_count']);
        $this->assertSame(2, $summary['top_10_count']);
        $this->assertSame(1, $summary['top_11_30']);
        $this->assertSame(1, $summary['not_ranking']);
    }

    public function test_average_position_ignores_unmeasured_keywords(): void
    {
        // Averaging nulls as zero would report a site ranking first for
        // everything it cannot measure.
        $customer = Customer::factory()->create();

        $this->ranking($customer, 'a', 10);
        $this->ranking($customer, 'b', 20);
        $this->ranking($customer, 'c', null);

        $summary = (new RankTrackingService($customer))->getSummary();

        $this->assertEqualsWithDelta(15.0, $summary['avg_position'], 0.01);
    }

    public function test_a_customer_with_no_data_reports_zeroes_not_nulls(): void
    {
        $summary = (new RankTrackingService(Customer::factory()->create()))->getSummary();

        $this->assertSame(0, $summary['total_keywords']);
        $this->assertSame(0, $summary['top_10_count']);
        $this->assertNull($summary['avg_position']);
    }
}
