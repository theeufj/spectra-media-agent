<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\FacebookAds\AdSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook edges are paged, and a first page looks exactly like a whole list.
 *
 * The Graph API returns 25 rows by default with a cursor for the rest. Reading
 * $response['data'] and stopping there meant an account's 40th ad set did not
 * exist as far as this platform was concerned — no error, no warning, just a
 * shorter list. It reached money: FetchFacebookAdsPerformanceData sums spend per
 * ad set, so spend past the first page was never collected and never billed.
 */
class FacebookPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_123']);

        // BaseFacebookAdsService needs some token to proceed.
        config(['facebook.system_user_token' => 'test-token']);
        config(['services.facebook.system_user_token' => 'test-token']);

        return $customer;
    }

    /** @param list<string> $ids */
    private function page(array $ids, ?string $after): array
    {
        $body = ['data' => array_map(fn ($id) => ['id' => $id, 'name' => "Ad set {$id}"], $ids)];

        if ($after !== null) {
            $body['paging'] = [
                'cursors' => ['after' => $after],
                'next' => 'https://graph.facebook.com/next-page',
            ];
        }

        return $body;
    }

    public function test_every_page_of_ad_sets_is_returned_not_just_the_first(): void
    {
        $responses = [
            Http::response($this->page(['1', '2'], 'CURSOR_A')),
            Http::response($this->page(['3', '4'], 'CURSOR_B')),
            Http::response($this->page(['5'], null)),   // last page: no next link
        ];

        Http::fake(['graph.facebook.com/*' => Http::sequence()->pushResponse($responses[0])
            ->pushResponse($responses[1])->pushResponse($responses[2])]);

        $adSets = (new AdSetService($this->customer()))->listAdSets('camp_1');

        $this->assertSame(['1', '2', '3', '4', '5'], array_column($adSets, 'id'));
    }

    public function test_paging_follows_the_cursor_it_was_given(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->pushResponse(Http::response($this->page(['1'], 'CURSOR_A')))
            ->pushResponse(Http::response($this->page(['2'], null)))]);

        (new AdSetService($this->customer()))->listAdSets('camp_1');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=CURSOR_A'));
    }

    public function test_a_single_page_makes_exactly_one_request(): void
    {
        // A cursor is present on the last page too, so keying "there is more" on
        // the cursor rather than the next link loops until the page cap.
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [['id' => '1']],
            'paging' => ['cursors' => ['before' => 'B', 'after' => 'A']],
        ])]);

        $adSets = (new AdSetService($this->customer()))->listAdSets('camp_1');

        $this->assertCount(1, $adSets);
        Http::assertSentCount(1);
    }

    public function test_a_failed_page_returns_what_was_already_collected(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->pushResponse(Http::response($this->page(['1', '2'], 'CURSOR_A')))
            ->pushResponse(Http::response(null, 500))]);

        $adSets = (new AdSetService($this->customer()))->listAdSets('camp_1');

        $this->assertSame(['1', '2'], array_column($adSets, 'id'));
    }
}
