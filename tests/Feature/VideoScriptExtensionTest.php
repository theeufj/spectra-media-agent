<?php

namespace Tests\Feature;

use App\Jobs\ExtendVideoForScript;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\VideoCollateral;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Videos must narrate their whole script. Veo produces 8 seconds per call
 * (~19 words), so longer scripts are split into segments and covered by
 * chained extensions — clips used to just end mid-sentence.
 */
class VideoScriptExtensionTest extends TestCase
{
    use DatabaseTransactions;

    private const LONG_SCRIPT = 'Stop wasting thousands on slow ad agencies. Just enter your URL, and sitetospend deploys six AI agents to run your ads twenty-four-seven for just one-forty-nine a month. Start scaling without the markup at sitetospend.com.';

    public function test_scripts_split_into_eight_second_segments_on_sentence_boundaries(): void
    {
        $segments = ExtendVideoForScript::scriptSegments(self::LONG_SCRIPT);

        $this->assertGreaterThanOrEqual(2, count($segments));
        // Nothing lost: every word of the script appears across the segments.
        $this->assertSame(
            str_word_count(self::LONG_SCRIPT),
            array_sum(array_map(str_word_count(...), $segments))
        );
        // First segment fits a clip (sentence boundaries can slightly exceed
        // the target, but never wildly).
        $this->assertLessThanOrEqual(ExtendVideoForScript::WORDS_PER_SEGMENT + 10, str_word_count($segments[0]));
    }

    public function test_a_short_script_needs_no_extension(): void
    {
        $video = $this->video(['script' => 'Five words is plenty here.', 'extension_count' => 0]);

        $this->assertFalse(ExtendVideoForScript::needsExtension($video));
    }

    public function test_a_long_script_needs_extension_until_all_segments_are_covered(): void
    {
        $segments = count(ExtendVideoForScript::scriptSegments(self::LONG_SCRIPT));

        $fresh = $this->video(['script' => self::LONG_SCRIPT, 'extension_count' => 0]);
        $this->assertTrue(ExtendVideoForScript::needsExtension($fresh));

        $covered = $this->video(['script' => self::LONG_SCRIPT, 'extension_count' => $segments - 1]);
        $this->assertFalse(ExtendVideoForScript::needsExtension($covered));
    }

    public function test_the_extension_cap_stops_runaway_chains(): void
    {
        $video = $this->video([
            'script' => str_repeat('This sentence pads the script well past every limit. ', 30),
            'extension_count' => ExtendVideoForScript::MAX_EXTENSIONS,
        ]);

        $this->assertFalse(ExtendVideoForScript::needsExtension($video));
    }

    public function test_incomplete_or_scriptless_videos_are_left_alone(): void
    {
        $this->assertFalse(ExtendVideoForScript::needsExtension(
            $this->video(['script' => self::LONG_SCRIPT, 'status' => 'generating'])
        ));
        $this->assertFalse(ExtendVideoForScript::needsExtension(
            $this->video(['script' => null])
        ));
    }

    private function video(array $attrs): VideoCollateral
    {
        $customer = Customer::factory()->create();
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        return VideoCollateral::create(array_merge([
            'campaign_id' => $campaign->id,
            'platform' => 'Facebook Ads',
            'status' => 'completed',
            's3_path' => 'collateral/videos/x.mp4',
            'is_active' => true,
            'extension_count' => 0,
        ], $attrs));
    }
}
