<?php

namespace Tests\Feature;

use App\Jobs\TrackKeywordRankings;
use App\Models\Customer;
use App\Models\Keyword;
use App\Models\SeoRanking;
use App\Services\SEO\RankTrackingService;
use App\Services\SEO\SearchConsoleService;
use App\Support\WorkStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrganicSearchMeasurementsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_real_organic_queries_keep_decimal_position_window_and_landing_page_independent_of_paid_terms(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        Keyword::create(['customer_id' => $customer->id, 'keyword_text' => 'unrelated paid keyword', 'match_type' => 'PHRASE', 'status' => 'active']);
        SeoRanking::create(['customer_id' => $customer->id, 'keyword' => 'organic query', 'domain' => 'example.com', 'date' => now()->toDateString(), 'position' => 99]);
        $search = $this->createMock(SearchConsoleService::class);
        $search->expects($this->exactly(2))->method('isVerified')->willReturn(true);
        $search->expects($this->exactly(4))->method('performance')->willReturnCallback(fn ($c, $dimension, $days, $limit) => $dimension === 'query' ? [
            'success' => true, 'reporting_start' => '2026-09-07', 'reporting_end' => '2026-10-04',
            'rows' => [['key' => 'organic query', 'position' => 7.612, 'clicks' => 3, 'impressions' => 42, 'ctr' => 3 / 42]],
        ] : [
            'success' => true, 'rows' => [
                ['key' => 'organic query', 'page' => 'https://example.com/low', 'impressions' => 2],
                ['key' => 'organic query', 'page' => 'https://example.com/google-ads', 'impressions' => 40],
            ],
        ]);
        $this->app->instance(SearchConsoleService::class, $search);
        $run = WorkStatus::start($customer->id, 'rankings');
        (new TrackKeywordRankings($customer->id, $run))->handle();
        (new TrackKeywordRankings($customer->id, WorkStatus::start($customer->id, 'rankings')))->handle();
        $this->assertSame(1, SeoRanking::where('customer_id', $customer->id)->where('source', 'google_search_console')->count());
        $row = SeoRanking::where('customer_id', $customer->id)->where('source', 'google_search_console')->firstOrFail();
        $this->assertSame(7.612, $row->average_position);
        $this->assertNull($row->position);
        $this->assertSame('https://example.com/google-ads', $row->url);
        $this->assertSame('2026-09-07', $row->reporting_start->toDateString());
        $this->assertSame('2026-10-04', $row->reporting_end->toDateString());
        $this->assertDatabaseHas('seo_rankings', ['customer_id' => $customer->id, 'source' => 'legacy_unknown', 'position' => 99]);
        $this->assertDatabaseMissing('seo_rankings', ['customer_id' => $customer->id, 'keyword' => 'unrelated paid keyword']);
        $this->assertSame(7.612, (new RankTrackingService($customer))->getSummary()['avg_position']);
    }

    public function test_missing_property_access_preserves_existing_measurements(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        $row = SeoRanking::create(['customer_id' => $customer->id, 'keyword' => 'saved query', 'domain' => 'example.com', 'date' => now()->subDay()->toDateString(), 'position' => 4]);
        $search = $this->createMock(SearchConsoleService::class);
        $search->expects($this->once())->method('isVerified')->willReturn(false);
        $this->app->instance(SearchConsoleService::class, $search);
        $result = (new RankTrackingService($customer))->trackOrganicQueries();
        $this->assertFalse($result['success']);
        $this->assertSame(4, $row->fresh()->position);
        $this->assertSame(1, (new RankTrackingService($customer))->getSummary()['total_keywords']);
    }
}
