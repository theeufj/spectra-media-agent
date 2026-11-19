<?php

namespace Tests\Feature;

use App\Jobs\GenerateStrategyCollateral;
use App\Jobs\GenerateVideo;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Models\VideoCollateral;
use App\Prompts\StrategyPrompt;
use App\Prompts\VideoFromScriptPrompt;
use App\Services\GeminiService;
use App\Services\VideoGeneration\VideoGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Video had the same bug as the images, and an expensive one to leave.
 *
 * Two videos went out per campaign — 16:9 for Google, 9:16 for Meta — and both
 * omitted the variation index, so both took Variation A, the problem-led
 * script, over the same footage. VideoScriptPrompt has had an aspiration-led
 * Variation B the entire time and nothing had ever asked for it.
 *
 * Unlike the images, multiplying this has a real cost. Measured from ai_costs:
 * $1.88 a video on Grok, $3.20 per 8-second segment when the Veo fallback runs,
 * so a 24-second script is $9.60 — about what 240 images cost. So the set is
 * bounded twice: by config, and by the plan's own video allowance, which had no
 * gate at all before this.
 */
class VideoConceptSetTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{Campaign, Strategy} */
    private function signedOffCampaign(int $videoAllowance): array
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $campaign->customer->users()->attach($user->id, ['role' => 'owner']);

        $plan = Plan::firstOrCreate(['slug' => 'test-video-allowance'], [
            'name' => 'Test', 'price_cents' => 0, 'billing_interval' => 'month',
        ]);
        $plan->forceFill(['creative_limits' => ['video_generations' => $videoAllowance]])->save();
        $campaign->customer->forceFill(['plan_id' => $plan->id])->save();

        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'imagery_strategy' => 'A boutique maker in a sunlit studio.',
            'video_strategy' => 'Show the maker building their store and taking a first order.',
            'signed_off_at' => now(),
        ]);

        return [$campaign->fresh(), $strategy];
    }

    /** @return list<int> */
    private function dispatchedConcepts(): array
    {
        $concepts = [];
        Queue::assertPushed(GenerateVideo::class, function ($job) use (&$concepts) {
            $concepts[] = (new \ReflectionProperty($job, 'variationIndex'))->getValue($job);

            return true;
        });
        sort($concepts);

        return $concepts;
    }

    public function test_video_renderer_receives_the_brand_palette_with_a_custom_template(): void
    {
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 5);
        $campaign->customer->brandGuideline()->create([
            'brand_voice' => [],
            'tone_attributes' => [],
            'color_palette' => ['primary_colors' => ['#FF4D00'], 'secondary_colors' => ['#FFC300']],
            'typography' => [],
            'visual_style' => [],
            'messaging_themes' => [],
            'unique_selling_propositions' => [],
            'target_audience' => [],
            'brand_personality' => [],
            'extracted_at' => now(),
        ]);
        $strategy->update(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'generate_video' => true]);
        Setting::set(VideoFromScriptPrompt::TEMPLATE_SETTING, "Create an animated short.\n{{voiceover_script}}", 'string');
        config(['ai.video_provider' => 'xai', 'services.xai.api_key' => 'test-xai-key']);
        Cache::forget('xai:unavailable');
        Cache::forget('xai:unavailable:model:'.config('ai.models.video_xai'));
        Http::fake(['api.x.ai/v1/videos/generations' => Http::response(['request_id' => 'test-video-request'])]);
        $narration = $this->createMock(GeminiService::class);
        $narration->expects($this->once())->method('generateContent')->willReturn(['text' => 'Meet the product.']);

        (new GenerateVideo($campaign->fresh(), $strategy->fresh(), 'facebook'))->handle(app(VideoGenerationService::class), $narration);

        Http::assertSent(fn ($request) => str_contains($request['prompt'], 'Meet the product.')
            && str_contains($request['prompt'], '**Palette:** orange, warm gold')
            && str_contains($request['prompt'], 'main accent')
            && ! str_contains($request['prompt'], '#FF4D00')
            && $request['aspect_ratio'] === '9:16');
    }

    public function test_manual_video_request_queues_a_render_when_automatic_video_is_off(): void
    {
        Queue::fake();
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 5);
        $strategy->update(['platform' => 'Facebook Ads', 'campaign_type' => 'display', 'generate_video' => false]);

        $this->actingAs($campaign->customer->users()->first())
            ->post(route('campaigns.collateral.video.store', [$campaign, $strategy]), ['platform' => 'Facebook Ads'])
            ->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        Queue::assertPushed(GenerateVideo::class, fn ($job) => (new \ReflectionProperty($job, 'force'))->getValue($job));
    }

    public function test_two_concepts_go_out_each_in_both_shapes(): void
    {
        Queue::fake();
        config(['ai.video_concepts_per_campaign' => 2]);
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 10);

        (new GenerateStrategyCollateral($campaign, $strategy, $campaign->customer->users()->first()->id))->handle();

        // Concept 0 landscape + portrait, concept 1 landscape + portrait. The
        // two shapes share a concept on purpose: one ad, two placements.
        $this->assertSame([0, 0, 1, 1], $this->dispatchedConcepts());
    }

    public function test_the_plan_allowance_caps_the_set(): void
    {
        Queue::fake();
        config(['ai.video_concepts_per_campaign' => 3]);
        // Room for two videos means room for one concept, not one and a half.
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 2);

        (new GenerateStrategyCollateral($campaign, $strategy, $campaign->customer->users()->first()->id))->handle();

        $this->assertSame([0, 0], $this->dispatchedConcepts());
    }

    public function test_a_plan_with_no_video_allowance_generates_none(): void
    {
        Queue::fake();
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 0);

        (new GenerateStrategyCollateral($campaign, $strategy, $campaign->customer->users()->first()->id))->handle();

        // The free tier. Video is the one place where generating anyway is
        // measurably expensive rather than merely generous.
        Queue::assertNotPushed(GenerateVideo::class);
    }

    public function test_the_search_example_in_the_prompt_does_not_dispatch_videos(): void
    {
        Queue::fake();
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 10);
        $campaign->update(['reason' => 'Create a Google Search campaign.']);

        // A prose explanation without the short N/A marker is treated as a
        // creative brief by the dispatcher, even when generate_video is false.
        $prompt = StrategyPrompt::build($campaign);
        $this->assertSame(1, preg_match('/```json\s*(.*?)\s*```/s', $prompt, $matches));
        $examples = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $search = collect($examples['strategies'])->firstWhere('platform', 'Google Ads (SEM)');
        $this->assertFalse($search['generate_video']);
        $strategy->update(['video_strategy' => $search['video_strategy'], 'generate_video' => false]);

        (new GenerateStrategyCollateral($campaign, $strategy, $campaign->customer->users()->first()->id))->handle();

        Queue::assertNotPushed(GenerateVideo::class);
    }

    public function test_videos_already_made_count_against_the_allowance(): void
    {
        Queue::fake();
        config(['ai.video_concepts_per_campaign' => 2]);
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 4);

        VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (Performance Max)',
            'status' => 'completed',
        ]);
        VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => 'Facebook Ads',
            // Failed ones do not count: a video that never arrived is not one
            // the customer received.
            'status' => 'failed',
        ]);

        (new GenerateStrategyCollateral($campaign, $strategy, $campaign->customer->users()->first()->id))->handle();

        // 4 allowed, 1 genuinely used, so 3 remain — room for one concept.
        $this->assertSame([0, 0], $this->dispatchedConcepts());
    }

    public function test_video_reservations_distinguish_concepts_and_enforce_the_allowance(): void
    {
        [$campaign, $strategy] = $this->signedOffCampaign(videoAllowance: 2);
        $attributes = ['platform' => 'Facebook Ads', 'variation_index' => 0, 'status' => 'pending', 'is_active' => true];
        $this->assertNotNull(VideoCollateral::reserve($campaign, $attributes));
        $this->assertNull(VideoCollateral::reserve($campaign, $attributes));
        $this->assertNotNull(VideoCollateral::reserve($campaign, array_replace($attributes, ['variation_index' => 1])));
        $this->assertNull(VideoCollateral::reserve($campaign, array_replace($attributes, ['variation_index' => 2])));
    }
}
