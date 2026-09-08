<?php

namespace Tests\Feature;

use App\Jobs\GenerateStrategy;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * cpa_target holds micros, and it was int4.
 *
 * That put the ceiling at a $2,147.48 target CPA — routine for B2B — and the
 * write that crossed it raised SQLSTATE 22003 partway through GenerateStrategy's
 * per-platform loop, abandoning the strategies already created for the earlier
 * platforms of the same campaign.
 */
class StrategyCpaTargetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_target_cpa_above_the_old_int4_ceiling_is_storable(): void
    {
        // $5,000 in micros — past int4's 2,147,483,647.
        $strategy = Strategy::factory()->create(['cpa_target' => 5_000_000_000]);

        $this->assertEquals(5_000_000_000, $strategy->fresh()->cpa_target);
    }

    public function test_strategy_versions_holds_the_same_range(): void
    {
        // Otherwise the widening only moves the failure to the rollback path,
        // which copies cpa_target straight back out of this table.
        $strategy = Strategy::factory()->create(['cpa_target' => 5_000_000_000]);

        DB::table('strategy_versions')->insert([
            'strategy_id' => $strategy->id,
            'campaign_id' => $strategy->campaign_id,
            'platform' => $strategy->platform,
            'ad_copy_strategy' => $strategy->ad_copy_strategy,
            'imagery_strategy' => $strategy->imagery_strategy,
            'video_strategy' => $strategy->video_strategy,
            'cpa_target' => 5_000_000_000,
            'versioned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = DB::table('strategy_versions')->where('strategy_id', $strategy->id)->first();

        $this->assertEquals(5_000_000_000, $version->cpa_target);
    }

    public function test_an_unusable_target_cpa_is_discarded_rather_than_taking_the_generation_down(): void
    {
        $job = new GenerateStrategy(Campaign::factory()->create());
        $normalise = new ReflectionMethod(GenerateStrategy::class, 'cpaTargetMicros');

        // Whatever the model returned, the loop has to survive it: null means
        // "no target", which every reader already handles.
        $this->assertNull($normalise->invoke($job, null));
        $this->assertNull($normalise->invoke($job, 'N/A'));
        $this->assertNull($normalise->invoke($job, ['micros' => 100]));
        $this->assertNull($normalise->invoke($job, -1));
        $this->assertNull($normalise->invoke($job, 1e30));

        // Real targets pass through untouched, whatever shape the JSON gave them.
        $this->assertSame(5_000_000_000, $normalise->invoke($job, 5_000_000_000));
        $this->assertSame(5_000_000_000, $normalise->invoke($job, '5000000000'));
        $this->assertSame(2_500_000, $normalise->invoke($job, 2_500_000.0));
    }
}
