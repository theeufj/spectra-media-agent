<?php

namespace Tests\Feature\Analytics;

use App\Models\SignupFunnelJourney;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignupFunnelRetentionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expired_steps_and_pending_links_are_removed_while_accounts_and_recent_journeys_survive(): void
    {
        $this->freezeSecond();
        $expired = (string) Str::uuid();
        $recent = (string) Str::uuid();
        foreach ([$expired => 91, $recent => 89] as $id => $days) {
            $started = now()->subDays($days)->toIso8601String();
            DB::table('signup_funnel_journeys')->insert(['id' => $id, 'source' => 'unattributed', 'started_at' => $started]);
            DB::table('signup_funnel_stages')->insert(['journey_id' => $id, 'stage' => 'signup_completed', 'occurred_at' => $started]);
        }
        $user = User::factory()->create(['signup_funnel_journey_id' => $expired]);

        $this->artisan('model:prune', ['--model' => [SignupFunnelJourney::class]])->assertSuccessful();

        $this->assertDatabaseMissing('signup_funnel_journeys', ['id' => $expired]);
        $this->assertDatabaseMissing('signup_funnel_stages', ['journey_id' => $expired]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'signup_funnel_journey_id' => null]);
        $this->assertDatabaseHas('signup_funnel_journeys', ['id' => $recent]);
        $this->assertDatabaseHas('signup_funnel_stages', ['journey_id' => $recent, 'stage' => 'signup_completed']);
    }
}
