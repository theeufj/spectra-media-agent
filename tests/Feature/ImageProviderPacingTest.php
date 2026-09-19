<?php

namespace Tests\Feature;

use App\Models\AiCost;
use App\Services\GeminiImagePacer;
use App\Services\GeminiService;
use App\Services\XaiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ImageProviderPacingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
        config([
            'services.google.project_id' => 'pacing-test',
            'services.xai.api_key' => 'fake-key',
            'ai.gemini_images.interval_seconds' => 15,
            'ai.gemini_images.max_wait_seconds' => 120,
            'ai.gemini_images.cooldown_seconds' => 60,
        ]);
        Cache::put('gcp_vertex_access_token', 'fake-token', 3000);
        Cache::forget('xai:unavailable');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    private function image(): array
    {
        return [['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['data' => 'image-data', 'mimeType' => 'image/png']],
        ]]]]]];
    }

    public function test_xai_uses_aspect_ratio_without_the_unsupported_size_and_records_cost(): void
    {
        Http::fake(['api.x.ai/*' => Http::response(['data' => [['b64_json' => 'image-data']]])]);

        foreach (['1:1', '16:9', '4:3'] as $aspect) {
            $result = app(XaiService::class)->generateImage('An advert', aspectRatio: $aspect);
            $this->assertSame('image-data', $result['data']);
            Http::assertSent(fn ($request) => $request['aspect_ratio'] === $aspect
                && $request['model'] === config('ai.models.image_xai')
                && $request['response_format'] === 'b64_json'
                && ! array_key_exists('size', $request->data()));
        }

        Http::assertSentCount(3);
        $this->assertSame(3, AiCost::where('service', 'xAI')->where('operation', 'generateImage')->count());
    }

    public function test_separate_service_instances_share_pacing_for_generation_and_refinement(): void
    {
        $sentAt = [];
        Http::fake(function () use (&$sentAt) {
            $sentAt[] = now()->timestamp;

            return Http::response($this->image());
        });

        $this->assertNotNull((new GeminiService)->generateImage('First'));
        $this->assertNotNull((new GeminiService)->refineImage('Second', []));
        $this->assertNotNull((new GeminiService)->generateImage('Third'));

        $this->assertSame([15, 15], [$sentAt[1] - $sentAt[0], $sentAt[2] - $sentAt[1]]);
    }

    public function test_retry_after_is_shared_and_repeated_429s_increase_cooldown(): void
    {
        $sentAt = [];
        Http::fake(function () use (&$sentAt) {
            $sentAt[] = now()->timestamp;

            return count($sentAt) <= 2
                ? Http::response(['error' => ['message' => 'Resource exhausted']], 429, ['Retry-After' => '75'])
                : Http::response($this->image());
        });

        $this->assertNull((new GeminiService)->generateImage('First'));
        $this->assertNull((new GeminiService)->refineImage('Second', []));
        $this->assertNotNull((new GeminiService)->generateImage('Third'));
        $this->assertSame([75, 120], [$sentAt[1] - $sentAt[0], $sentAt[2] - $sentAt[1]]);
    }

    public function test_long_vertex_retry_info_stops_other_workers_from_sending_until_ready(): void
    {
        Http::fakeSequence()
            ->push([['error' => ['details' => [['retryDelay' => '240s']]]]], 429)
            ->push($this->image());

        $this->assertNull((new GeminiService)->generateImage('First'));
        $this->assertNull((new GeminiService)->refineImage('Too soon', []));
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        $this->travel(240)->seconds();
        $this->assertNotNull((new GeminiService)->generateImage('After cooldown'));
        Http::assertSentCount(2);
    }

    public function test_http_date_retry_after_is_honoured(): void
    {
        $start = now()->timestamp;
        Http::fakeSequence()
            ->push([], 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()])
            ->push($this->image());

        $this->assertNull((new GeminiService)->generateImage('First'));
        $this->assertNotNull((new GeminiService)->generateImage('Second'));
        $this->assertSame(90, now()->timestamp - $start);
    }

    public function test_a_waiting_worker_rechecks_a_cooldown_set_by_another_worker(): void
    {
        $start = now()->timestamp;
        Http::fake(['aiplatform.googleapis.com/*' => Http::response($this->image())]);
        $this->assertNotNull((new GeminiService)->generateImage('First'));

        $cooledDown = false;
        Sleep::whenFakingSleep(function () use (&$cooledDown): void {
            if (! $cooledDown) {
                $cooledDown = true;
                $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(429));
                (new GeminiImagePacer)->coolDown(config('ai.models.image'), $response);
            }
        });

        $this->assertNotNull((new GeminiService)->generateImage('Second'));
        $this->assertSame(75, now()->timestamp - $start);
        Http::assertSentCount(2);
    }

    public function test_a_later_shorter_cooldown_cannot_expire_an_existing_long_cooldown(): void
    {
        $pacer = new GeminiImagePacer;
        $model = config('ai.models.image');
        $response = fn (int $seconds) => new \Illuminate\Http\Client\Response(
            new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => (string) $seconds])
        );

        $pacer->coolDown($model, $response(900));
        $this->travel(10)->seconds();
        $pacer->coolDown($model, $response(60));
        $this->travel(300)->seconds();

        $this->assertFalse($pacer->awaitTurn($model));
        Sleep::assertNeverSlept();
        $this->travel(590)->seconds();
        $this->assertTrue($pacer->awaitTurn($model));
    }
}
