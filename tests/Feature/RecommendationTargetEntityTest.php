<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * target_entity is a json column that a `->change()` quietly turned into
 * varchar(255).
 *
 * The model still casts it to array, so every write JSON-encodes into those 255
 * characters. When one overflowed, the insert aborted the campaign *after*
 * applyRecommendation() had already pushed the change to the ad platform and
 * before last_optimized_at was stamped — so the optimiser did it all again on
 * the next run.
 */
class RecommendationTargetEntityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_target_entity_longer_than_255_characters_round_trips(): void
    {
        $campaign = Campaign::factory()->create();

        $target = [
            'campaign_id' => (string) $campaign->id,
            // Google resource names are this long once the ad group is included.
            'ad_groups' => array_fill(0, 8, 'customers/1234567890/adGroups/9876543210'),
        ];

        $this->assertGreaterThan(255, strlen((string) json_encode($target)));

        $recommendation = Recommendation::create([
            'campaign_id' => $campaign->id,
            'type' => 'budget_adjustment',
            'target_entity' => $target,
            'parameters' => ['new_budget_amount' => 250],
            'rationale' => 'CPA is under target with room to scale.',
        ]);

        $this->assertEquals($target, $recommendation->fresh()->target_entity);
    }
}
