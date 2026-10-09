<?php

namespace Tests\Feature;

use App\Jobs\CheckSearchIndexing;
use App\Jobs\Scheduled\DispatchSearchIndexingChecks;
use App\Models\Customer;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\SEO\IndexingHealthService;
use App\Services\SEO\SearchConsoleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IndexingHealthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_canonical_on_another_domain_and_unindexed_commercial_page_are_actionable(): void
    {
        $search = $this->createMock(SearchConsoleService::class);
        $search->expects($this->once())->method('inspectUrl')->willReturn([
            'success' => true, 'verdict' => 'FAIL', 'google_canonical' => 'https://other.example/features',
            'user_canonical' => 'https://example.com/features', 'coverage_state' => 'Duplicate, Google chose different canonical than user',
        ]);
        $report = (new IndexingHealthService($search, app(PublicWebsiteFetcher::class)))->inspect(Customer::factory()->create(), ['https://example.com/features']);
        $this->assertSame(['canonical_other_domain', 'page_not_indexed'], array_column($report['issues'], 'code'));
        $this->assertSame(['critical', 'critical'], array_column($report['issues'], 'severity'));
        $this->assertStringContainsString('after Google recrawls', $report['issues'][0]['action']);
    }

    public function test_missing_inspection_access_stops_the_batch_and_never_reports_pages_as_indexed(): void
    {
        $search = $this->createMock(SearchConsoleService::class);
        $search->expects($this->once())->method('inspectUrl')->willReturn(['success' => false, 'error' => 'Property access required']);
        $report = (new IndexingHealthService($search, app(PublicWebsiteFetcher::class)))->inspect(Customer::factory()->create(), ['https://example.com/', 'https://example.com/features']);
        $this->assertSame(0, $report['checked_pages']);
        $this->assertSame(2, $report['requested_pages']);
        $this->assertSame('inspection_unavailable', $report['issues'][0]['code']);
    }

    public function test_sitemap_monitor_stores_results_and_ignores_other_domain_urls(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com/']);
        $search = $this->createMock(SearchConsoleService::class);
        $search->expects($this->exactly(2))->method('inspectUrl')->willReturnCallback(fn ($c, $url) => [
            'success' => true, 'url' => $url, 'verdict' => 'PASS', 'google_canonical' => $url, 'user_canonical' => $url,
        ]);
        // Laravel's response wrapper is the concrete return type of the fetcher.
        $fetcher = $this->createMock(PublicWebsiteFetcher::class);
        $fetcher->expects($this->once())->method('get')->willReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/features</loc></url><url><loc>https://other.example/</loc></url></urlset>')));
        $audit = (new IndexingHealthService($search, $fetcher))->auditSite($customer);
        $this->assertNull($audit->score);
        $this->assertSame(2, $audit->indexing_analysis['checked_pages']);
        $this->assertSame([], $audit->issues);
    }

    public function test_scheduled_monitor_dispatches_for_websites_without_paid_keywords(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com/']);
        (new DispatchSearchIndexingChecks)->handle();
        Queue::assertPushed(CheckSearchIndexing::class, fn ($job) => $job->customerId === $customer->id);
    }

    public function test_manual_check_uses_the_selected_owned_customer_and_survives_duplicate_clicks(): void
    {
        $user = \App\Models\User::factory()->create();
        $customer = Customer::factory()->create(['website' => 'https://example.com/']);
        $other = Customer::factory()->create(['website' => 'https://other.example/']);
        $user->customers()->attach($customer);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->post(route('seo.indexing.check'), ['customer_id' => $other->id])->assertRedirect();
        $this->post(route('seo.indexing.check'))->assertRedirect();
        Queue::assertPushed(CheckSearchIndexing::class, 1);
        Queue::assertPushed(CheckSearchIndexing::class, fn ($job) => $job->customerId === $customer->id && $job->workRunId !== null);
        Queue::assertNotPushed(CheckSearchIndexing::class, fn ($job) => $job->customerId === $other->id);
    }

    public function test_heading_checks_follow_document_order_and_count_real_tags(): void
    {
        $service = new class(Customer::factory()->create()) extends \App\Services\SEO\SeoAuditService
        {
            public function headings(string $html): array
            {
                return $this->analyzeHeadings($html);
            }
        };
        $headings = $service->headings('<h1>Title</h1><h3>Skipped heading</h3><h2>Later section</h2>');
        $this->assertFalse($headings['proper_hierarchy']);
        $this->assertSame(1, $headings['h2_count']);
        $this->assertSame(1, $headings['h3_count']);
    }
}
