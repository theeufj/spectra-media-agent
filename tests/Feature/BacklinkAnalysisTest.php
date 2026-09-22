<?php

namespace Tests\Feature;

use App\Jobs\RunBacklinkAnalysis;
use App\Models\Customer;
use App\Models\User;
use App\Services\FirecrawlService;
use App\Services\GeminiService;
use App\Services\SEO\BacklinkAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BacklinkAnalysisTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.moz.api_key' => 'test-moz-token', 'services.firecrawl.api_key' => null]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['website' => 'https://example.com', 'description' => null, 'business_type' => null]);
    }

    private function link(string $page = 'publisher.example/article'): array
    {
        return ['source' => ['page' => $page, 'root_domain' => 'publisher.example', 'domain_authority' => 70, 'spam_score' => 5],
            'target' => ['page' => 'example.com/offer'], 'anchor_text' => 'Example product', 'nofollow' => true,
            'date_first_seen' => '2026-01-01', 'date_last_seen' => '2026-09-20', 'date_disappeared' => '2026-02-01'];
    }

    private function metrics(): array
    {
        return ['results' => [['external_pages_to_root_domain' => 20, 'root_domains_to_root_domain' => 16, 'domain_authority' => 3]]];
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'lsapi.seomoz.com/v2/links' => Http::response(['results' => [$this->link()], 'next_token' => '']),
            'lsapi.seomoz.com/v2/url_metrics' => Http::response($this->metrics()),
        ]);
    }

    public function test_real_moz_shape_domain_metrics_and_valid_request_size(): void
    {
        $this->fakeSuccess();
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        Http::assertSent(fn ($request) => $request->url() === 'https://lsapi.seomoz.com/v2/links'
            && $request['limit'] === 50 && $request['target_scope'] === 'root_domain'
            && $request->hasHeader('x-moz-token', 'test-moz-token'));
        $this->assertSame('complete', $profile['status']);
        $this->assertSame(20, $profile['indexed_linking_pages']);
        $this->assertSame(16, $profile['referring_domains']);
        $this->assertSame(3, $profile['domain_authority'], 'Our DA is not the average DA of sources.');
        $this->assertSame(1, $profile['sample_size']);
        $this->assertSame('https://publisher.example/article', $profile['backlinks'][0]['source_url']);
        $this->assertSame('https://example.com/offer', $profile['backlinks'][0]['target_url']);
        $this->assertSame('nofollow', $profile['backlinks'][0]['rel']);
        $this->assertFalse($profile['backlinks'][0]['lost'], 'A link seen after its disappearance has returned.');
    }

    public function test_pagination_is_bounded_and_sample_count_is_not_the_domain_total(): void
    {
        Http::fake(['lsapi.seomoz.com/v2/*' => Http::sequence()
            ->push(['results' => [$this->link()], 'next_token' => 'next-one'])
            ->push(['results' => [$this->link('publisher.example/second')], 'next_token' => 'next-two'])
            ->push($this->metrics())]);
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => ($request['next_token'] ?? null) === 'next-one');
        $this->assertTrue($profile['sample_truncated']);
        $this->assertSame(2, $profile['sample_size']);
        $this->assertSame(20, $profile['indexed_linking_pages']);
    }

    public function test_provider_failure_and_malformed_success_never_become_zero_backlinks(): void
    {
        Http::fake(['lsapi.seomoz.com/v2/links' => Http::response(['message' => 'bad limit'], 400),
            'lsapi.seomoz.com/v2/url_metrics' => Http::response(['unexpected' => true])]);
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        $this->assertSame('unavailable', $profile['status']);
        $this->assertNull($profile['indexed_linking_pages']);
        $this->assertNull($profile['domain_authority']);
        $this->assertFalse($profile['sample_available']);
        $this->assertStringContainsString('HTTP 400', $profile['warnings'][0]);
        $this->assertSame([], $profile['opportunities']);
    }

    public function test_partial_data_keeps_the_link_sample_without_inventing_metrics(): void
    {
        Http::fake(['lsapi.seomoz.com/v2/links' => Http::response(['results' => [$this->link()]]),
            'lsapi.seomoz.com/v2/url_metrics' => Http::response([], 503)]);
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        $this->assertSame('partial', $profile['status']);
        $this->assertCount(1, $profile['backlinks']);
        $this->assertNull($profile['domain_authority']);
    }

    public function test_search_mentions_are_separate_from_backlinks_and_unsafe_urls_are_dropped(): void
    {
        config(['services.moz.api_key' => null]);
        $firecrawl = Mockery::mock(FirecrawlService::class);
        $firecrawl->shouldReceive('isConfigured')->andReturn(true);
        $firecrawl->shouldReceive('search')->andReturn(['success' => true, 'results' => [
            ['url' => 'https://news.example/article', 'title' => 'A mention'],
            ['url' => 'https://example.com/own-page'],
            ['url' => 'javascript:alert(1)'],
        ]]);
        $this->app->instance(FirecrawlService::class, $firecrawl);
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        $this->assertCount(1, $profile['mentions']);
        $this->assertSame([], $profile['backlinks']);
        $this->assertNull($profile['indexed_linking_pages']);
        Http::assertNothingSent();
    }

    public function test_lost_links_and_review_flags_come_from_index_evidence_not_domain_words(): void
    {
        $link = $this->link('loan-advice.example/article');
        $link['date_disappeared'] = '2026-09-21';
        $link['source']['spam_score'] = 70;
        Http::fake(['lsapi.seomoz.com/v2/links' => Http::response(['results' => [$link]]),
            'lsapi.seomoz.com/v2/url_metrics' => Http::response($this->metrics())]);
        $profile = (new BacklinkAnalysisService($this->customer()))->analyze('example.com');
        $this->assertTrue($profile['backlinks'][0]['lost']);
        $this->assertSame(1, $profile['review_count']);
        $this->assertSame([], $profile['anchor_analysis']);
        $this->assertStringContainsString('does not prove', $profile['backlinks'][0]['review_reason']);
    }

    public function test_failed_refresh_retains_the_previous_report_and_finishes_with_visible_failure(): void
    {
        Http::fake(['lsapi.seomoz.com/v2/*' => Http::sequence()
            ->push(['results' => [$this->link()]])->push($this->metrics())
            ->push([], 503)->push([], 503)]);
        $customer = $this->customer();
        $service = new BacklinkAnalysisService($customer);
        $service->analyze('example.com');
        (new RunBacklinkAnalysis($customer->id, 'example.com'))->handle();
        $report = $service->report('example.com');
        $this->assertSame('failed', $report['run']['status']);
        $this->assertSame('complete', $report['profile']['status']);
        $this->assertSame(20, $report['profile']['indexed_linking_pages']);
    }

    public function test_refresh_is_scoped_queues_once_and_page_reads_do_not_call_providers(): void
    {
        $customer = $this->customer();
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $other = $this->customer();
        $service = new BacklinkAnalysisService($customer);
        Cache::put($service->key('example.com').':profile', ['indexed_linking_pages' => 20]);
        Cache::put((new BacklinkAnalysisService($other))->key('example.com').':profile', ['indexed_linking_pages' => 999]);
        $this->actingAs($user)->get(route('seo.backlinks'))->assertOk();
        $this->getJson(route('seo.backlinks.status'))->assertOk()->assertJsonPath('profile.indexed_linking_pages', 20);
        $this->post(route('seo.backlinks.refresh'), ['customer_id' => $other->id, 'domain' => 'other.example'])->assertRedirect();
        $this->post(route('seo.backlinks.refresh'))->assertRedirect();
        Queue::assertPushed(RunBacklinkAnalysis::class, 1);
        Queue::assertPushed(RunBacklinkAnalysis::class, fn ($job) => $job->customerId === $customer->id && $job->domain === 'example.com');
        Http::assertNothingSent();
    }

    public function test_ai_ideas_use_the_business_profile_and_are_not_confirmed_opportunities(): void
    {
        $this->fakeSuccess();
        $customer = $this->customer();
        $customer->update(['description' => 'Software that manages Google Ads for small businesses.', 'business_type' => 'Advertising software']);
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateContent')->withArgs(function ($model, $prompt) {
            $this->assertStringContainsString('manages Google Ads', $prompt);
            $this->assertStringContainsString('not confirmed placement opportunities', $prompt);
            $this->assertStringContainsString('publisher.example/article', $prompt);

            return true;
        })->andReturn(['text' => '[{"description":"Research advertising software comparison sites."}]']);
        $this->app->instance(GeminiService::class, $gemini);
        $profile = (new BacklinkAnalysisService($customer))->analyze('example.com');
        $this->assertCount(1, $profile['opportunities']);
    }
}
