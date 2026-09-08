<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CreativeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Graph API exposes GET/POST/DELETE only — a node update is a POST to the
 * node. Ad-set and ad updates used to send PUT, which came back 400, so every
 * Facebook mutation (dayparting, schedules, creative swaps, pauseAd()) was a
 * silent no-op: the caller just saw false and a disapproved ad kept serving.
 *
 * The resumable video upload had the matching shape of bug — one chunk sent,
 * "finished" declared, and an id read off a response that never carries one.
 */
class FacebookGraphWriteTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => 'act_123']);

        // BaseFacebookAdsService needs some token to proceed.
        config(['services.facebook.system_user_token' => 'test-token']);

        return $customer;
    }

    public function test_updating_an_ad_posts_to_the_node_rather_than_putting(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $updated = (new AdService($this->customer()))->updateAd('ad_1', ['status' => 'PAUSED']);

        $this->assertTrue($updated);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/ad_1')
            && $request['status'] === 'PAUSED');

        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_updating_an_ad_set_posts_to_the_node_rather_than_putting(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $updated = (new AdSetService($this->customer()))->updateAdSet('adset_1', ['daily_budget' => 5000]);

        $this->assertTrue($updated);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/adset_1'));

        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    /**
     * @return array{0: CreativeService, 1: string} the service and the temp file path
     */
    private function creativeServiceWithVideoFile(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fb_video_test_');
        file_put_contents($path, $contents);

        return [new CreativeService($this->customer()), $path];
    }

    private function uploadLargeVideo(CreativeService $service, string $path): ?string
    {
        $method = new ReflectionMethod($service, 'uploadLargeVideo');
        $method->setAccessible(true);

        return $method->invoke($service, '123', $path);
    }

    public function test_resumable_upload_transfers_every_chunk_and_returns_the_start_phase_id(): void
    {
        // Facebook picks the window size; each transfer answers with the next one.
        // Offsets come back as strings, which is why they have to be cast.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['video_id' => 'vid_from_start', 'upload_session_id' => 'sess_1', 'start_offset' => '0', 'end_offset' => '10'])
            ->push(['start_offset' => '10', 'end_offset' => '20'])
            ->push(['start_offset' => '20', 'end_offset' => '30'])
            ->push(['start_offset' => '30', 'end_offset' => '30'])
            ->push(['success' => true])]);

        [$service, $path] = $this->creativeServiceWithVideoFile('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123');

        try {
            $videoId = $this->uploadLargeVideo($service, $path);
        } finally {
            @unlink($path);
        }

        // finish answers {"success": true} and carries no id — the id is the
        // start phase's, and reading it anywhere else returns null on success.
        $this->assertSame('vid_from_start', $videoId);

        $recorded = Http::recorded();
        $this->assertCount(5, $recorded, 'start + three transfers + finish');

        $transferBodies = [$recorded[1][0]->body(), $recorded[2][0]->body(), $recorded[3][0]->body()];

        $this->assertStringContainsString('ABCDEFGHIJ', $transferBodies[0]);
        $this->assertStringContainsString('KLMNOPQRST', $transferBodies[1]);
        $this->assertStringContainsString('UVWXYZ0123', $transferBodies[2]);

        $this->assertSame('finish', $recorded[4][0]['upload_phase']);
    }

    public function test_resumable_upload_stops_when_a_transfer_does_not_advance_the_offset(): void
    {
        // Same offset handed straight back: without the guard this loops forever
        // against the live API and hangs the worker.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['video_id' => 'vid_from_start', 'upload_session_id' => 'sess_1', 'start_offset' => '0', 'end_offset' => '10'])
            ->push(['start_offset' => '0', 'end_offset' => '10'])]);

        [$service, $path] = $this->creativeServiceWithVideoFile('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123');

        try {
            $videoId = $this->uploadLargeVideo($service, $path);
        } finally {
            @unlink($path);
        }

        $this->assertNull($videoId);
        Http::assertSentCount(2);
    }

    public function test_resumable_upload_reports_nothing_when_a_chunk_transfer_fails(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['video_id' => 'vid_from_start', 'upload_session_id' => 'sess_1', 'start_offset' => '0', 'end_offset' => '10'])
            ->push(['error' => ['message' => 'boom']], 400)]);

        [$service, $path] = $this->creativeServiceWithVideoFile('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123');

        try {
            $videoId = $this->uploadLargeVideo($service, $path);
        } finally {
            @unlink($path);
        }

        // A partial transfer must not be finalised — a half-uploaded video that
        // reports an id becomes a live ad pointing at an unusable asset.
        $this->assertNull($videoId);
        Http::assertSentCount(2);
    }
}
