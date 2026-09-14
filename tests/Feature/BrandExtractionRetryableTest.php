<?php

namespace Tests\Feature;

use App\Exceptions\BrandExtractionFailed;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A model that hiccuped is not a website that cannot be read.
 *
 * The extractor reported every outcome by returning null, and the job treated
 * every null as final: it mailed "we couldn't extract enough about your brand"
 * and stopped the onboarding chain dead. The job's own retry budget —
 * maxExceptions = 3, an hour of retryUntil — never engaged once, because a
 * returned null is not an exception and the queue had nothing to catch.
 *
 * Customer 44 is what that costs. Four pages crawled, 13,800 characters of
 * good content, one null from the extractor, and a dead end with a
 * manual-entry form as the only way forward. The identical call run again
 * scored 96 out of 100.
 *
 * So transient failures throw and are retried; genuine dead ends still return
 * null and still stop at once, because retrying those really is pointless.
 */
class BrandExtractionRetryableTest extends TestCase
{
    use DatabaseTransactions;

    private function customerWithContent(): Customer
    {
        $customer = Customer::factory()->create(['website' => 'https://example.test']);
        $user = User::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        KnowledgeBase::create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'url' => 'https://example.test/',
            'title' => 'Home',
            'content' => str_repeat('Real crawled copy about the business. ', 100),
        ]);

        return $customer->fresh();
    }

    public function test_a_model_that_returns_nothing_is_retried_rather_than_reported_as_a_bad_website(): void
    {
        $customer = $this->customerWithContent();

        // The transient case: the call itself fails.
        Http::fake(['*' => Http::response(['error' => 'rate limited'], 429)]);

        $this->expectException(BrandExtractionFailed::class);

        app(BrandGuidelineExtractorService::class)->extractGuidelines($customer);
    }

    public function test_malformed_output_is_retried_too(): void
    {
        $customer = $this->customerWithContent();

        // A model returning prose where JSON was asked for is the same kind of
        // failure as not answering: try again.
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Sorry, I cannot help with that.']]]]],
        ], 200)]);

        $this->expectException(BrandExtractionFailed::class);

        app(BrandGuidelineExtractorService::class)->extractGuidelines($customer);
    }

    public function test_a_customer_with_nothing_crawled_still_stops_immediately(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.test']);
        $customer->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

        Http::fake();

        /*
           No content is not a hiccup — no number of retries produces a brand
           from an empty knowledge base, and three attempts at it would only
           delay telling the customer. Null, and the job reports it.
        */
        $this->assertNull(
            app(BrandGuidelineExtractorService::class)->extractGuidelines($customer->fresh()),
        );
    }
}
