<?php

namespace Tests\Feature;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A column written through update() or fill() has to be listed in $fillable,
 * or Eloquent drops it and tells nobody — the request still succeeds, the
 * flash message still says it worked, and the value never lands.
 *
 * Three of them were live at once, all silent: banning a user left banned_at
 * NULL so CheckForBannedUser never fired, the profile form's ad-platform
 * choice never reached the column allowedPlatforms() reads, and excluding a
 * piece of ad copy from a deployment deployed it anyway.
 */
class SilentlyDroppedAttributesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        return $user;
    }

    public function test_banning_a_user_records_the_ban(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.users.ban', $user));

        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_a_banned_user_is_thrown_out_of_the_app(): void
    {
        // The column is only worth writing because CheckForBannedUser acts on
        // it. While the write was being discarded, "Ban" changed nothing at
        // all: the user carried on using the account.
        $user = User::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.users.ban', $user));

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unbanning_a_user_clears_the_ban(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.users.ban', $user));
        $this->actingAs($admin)->post(route('admin.users.unban', $user));

        $this->assertNull($user->fresh()->banned_at);
    }

    public function test_the_profile_platform_choice_reaches_the_column_allowed_platforms_reads(): void
    {
        $user = User::factory()->create();
        // profile.update sits behind ensureUserHasCustomer; without one the
        // middleware redirects and the controller never runs, which would make
        // this pass or fail for the wrong reason.
        $user->customers()->attach(Customer::factory()->create()->id, ['role' => 'owner']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'starter_platform' => 'facebook',
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('facebook', $user->fresh()->starter_platform);
    }

    public function test_excluding_ad_copy_from_a_deployment_sticks(): void
    {
        $user = User::factory()->create();
        $user->subscription_status = 'active';
        $user->save();

        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id]);
        $adCopy = AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads',
            'headlines' => ['Headline'],
            'descriptions' => ['Description'],
        ]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('deployment.toggle-collateral'), [
                'type' => 'ad_copy',
                'id' => $adCopy->id,
            ]);

        $this->assertFalse($adCopy->fresh()->should_deploy);
    }
}
