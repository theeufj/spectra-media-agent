<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminConversionDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_configuration_and_legacy_delivery_are_not_presented_as_google_success(): void
    {
        Cache::flush();
        Setting::set('conversion_resource_name.signup_import', 'customers/123/conversionActions/456');
        Setting::set('conversion_resource_name.signup', 'customers/123/conversionActions/111');
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        SpectraConversionEvent::create(['event' => 'signup', 'mode' => 'server_google',
            'user_id' => $admin->id, 'gclid' => 'private-click', 'uploaded_to_google' => true]);
        $this->actingAs($admin)->get(route('admin.conversions.index'))
            ->assertSuccessful()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ConversionTracking')
            ->where('actions', fn ($actions) => collect($actions)->firstWhere('key', 'signup')['resource_name'] === 'customers/123/conversionActions/456')
            ->where('delivery.events.signup.legacy_unverified', 1)
            ->where('delivery.events.signup.processed', 0)
            ->where('delivery.reported_in_google_ads', 'not_verified')
            ->where('recent_events.0.google_delivery_status', 'legacy_unverified')
            ->where('recent_events.0.has_google_click_identifier', true)
            ->missing('recent_events.0.gclid')->missing('recent_events.0.ad_identifiers'));
    }
}
