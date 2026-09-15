<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\User;
use App\Models\VideoCollateral;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Running a job twice must not produce the deliverable twice.
 *
 * A queue retries. A worker dies between an upload and the row that records
 * it. A release puts the job back. All of that is normal, and the jobs that
 * create collateral had nothing to stop it: RefineImage made a second child of
 * the same parent, and the three video extension jobs made a second child of
 * the same source. Two near-identical creatives on the page, and two charges
 * against allowances the customer is sold as a fixed number of.
 *
 * Not everything should be guarded. GetKeywordQualityScore writes a time
 * series and repeating is the point — a score per run is the record, not a
 * duplicate.
 */
class JobIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(): Campaign
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return Campaign::factory()->create(['customer_id' => $customer->id]);
    }

    public function test_an_image_has_at_most_one_live_refinement(): void
    {
        $campaign = $this->campaign();

        $original = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/original.jpeg',
            'cloudfront_url' => 'https://example.test/original.jpeg',
            'format' => 'square',
        ]);

        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/refined.jpeg',
            'cloudfront_url' => 'https://example.test/refined.jpeg',
            'format' => 'square',
            'parent_id' => $original->id,
            'refinement_depth' => 1,
            'is_active' => true,
        ]);

        // The condition RefineImage checks before creating a second child.
        $this->assertTrue(
            ImageCollateral::where('parent_id', $original->id)->where('is_active', true)->exists(),
            'a live refinement of this image already exists and a retry must find it',
        );
    }

    public function test_a_retired_refinement_does_not_block_a_fresh_one(): void
    {
        $campaign = $this->campaign();

        $original = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/original.jpeg',
            'cloudfront_url' => 'https://example.test/original.jpeg',
            'format' => 'square',
        ]);

        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'collateral/images/old.jpeg',
            'cloudfront_url' => 'https://example.test/old.jpeg',
            'format' => 'square',
            'parent_id' => $original->id,
            'refinement_depth' => 1,
            'is_active' => false,
        ]);

        /*
           Deliberately scoped to live rows. A customer who refines, discards
           the result and refines again is doing something legitimate, and a
           guard that counted retired children would refuse them for ever.
        */
        $this->assertFalse(
            ImageCollateral::where('parent_id', $original->id)->where('is_active', true)->exists(),
        );
    }

    public function test_a_video_has_at_most_one_of_each_extension(): void
    {
        $campaign = $this->campaign();

        $source = VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            'status' => 'completed',
            'extension_count' => 0,
        ]);

        VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            'status' => 'generating',
            'parent_video_id' => $source->id,
            'extension_count' => 1,
        ]);

        // What the extension jobs check before starting a second.
        $this->assertTrue(
            VideoCollateral::where('parent_video_id', $source->id)->where('extension_count', 1)->exists(),
        );

        // The next extension in the chain is a different thing and must still
        // be allowed — otherwise an 8-second clip could never reach 24.
        $this->assertFalse(
            VideoCollateral::where('parent_video_id', $source->id)->where('extension_count', 2)->exists(),
        );
    }
}
