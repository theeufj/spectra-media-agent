<?php

namespace Tests\Feature\Crawl;

use App\Jobs\CrawlPage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A page we were blocked from is not a page with nothing on it.
 *
 * One production store has 1,236 crawled pages and 3 with real text on them.
 * Shopify answered 1,205 of the requests with "local_rate_limited"; the
 * crawler stored that string as the page's content, paid for an embedding of
 * it, and the knowledge base then offered it up as a description of the
 * business. The remaining 27 were the browser's own error page — the same
 * cause.
 *
 * Nothing failed. A knowledge base full of refusals looks exactly like a
 * knowledge base full of thin pages, which is why it went unnoticed until
 * something tried to read from it.
 */
class CrawlPageBlockingTest extends TestCase
{
    use DatabaseTransactions;

    private function job(string $url = 'https://shop.test/products/dress'): CrawlPage
    {
        Customer::unsetEventDispatcher();

        return new CrawlPage(User::factory()->create(), $url, Customer::factory()->create()->id);
    }

    private function assertRejects(string $content): void
    {
        $method = new \ReflectionMethod(CrawlPage::class, 'assertNotBlocked');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke($this->job(), $content);
    }

    public function test_a_shopify_rate_limit_notice_is_not_stored_as_content(): void
    {
        // The exact string that filled 1,205 rows.
        $this->assertRejects('local_rate_limited');
    }

    public function test_the_browser_error_page_is_not_stored_as_content(): void
    {
        $this->assertRejects('There was a problem loading this website Try refreshing the page.');
    }

    public function test_a_bot_challenge_is_not_stored_as_content(): void
    {
        $this->assertRejects('Checking your browser before accessing the site.');
    }

    public function test_an_empty_body_is_not_stored_as_content(): void
    {
        $this->assertRejects('   ');
    }

    public function test_a_real_page_passes(): void
    {
        Http::fake();

        $method = new \ReflectionMethod(CrawlPage::class, 'assertNotBlocked');
        $method->setAccessible(true);

        $this->expectNotToPerformAssertions();

        // Returning without throwing is the behaviour under test.
        $method->invoke(
            $this->job(),
            'Our sequin wrap dress is cut from recycled satin and ships from Melbourne within two days.',
        );
    }

    public function test_the_job_is_rate_limited_and_retried(): void
    {
        $job = $this->job();

        // Deferrals must not consume retries. RateLimited releases the job back
        // to the queue and a release counts as an attempt, so a `tries` limit
        // would let a busy crawl fail pages purely for having been asked to
        // wait. maxExceptions counts only attempts that actually threw.
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThan(now(), $job->retryUntil());
        $this->assertNotEmpty($job->backoff());

        $middleware = $job->middleware();
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }
}
