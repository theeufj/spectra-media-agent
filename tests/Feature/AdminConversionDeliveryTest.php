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

    public function test_server_setup_payments_have_separate_delivery_counts_without_reclassifying_native_receipts(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        foreach (['server_google', 'verified_purchase'] as $mode) {
            SpectraConversionEvent::create(['event' => 'paid_setup', 'mode' => $mode, 'user_id' => $admin->id,
                'value' => '999.00', 'currency' => 'USD', 'occurred_at' => now(), 'ad_identifiers' => ['gclid' => 'private-click'],
                'google_conversion_resource' => 'customers/123/conversionActions/456', 'uploaded_to_google' => false,
                'upload_error' => $mode === 'server_google' ? 'Setup upload not accepted' : null]);
        }

        $this->actingAs($admin)->get(route('admin.conversions.index'))->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/ConversionTracking')
                ->where('delivery.events.paid_setup.registrations_or_payments', 1)
                ->where('delivery.events.paid_setup.upload_failed', 1)
                ->where('delivery.events.paid_setup.processed', 0)
                ->where('delivery.events.paid_subscription.registrations_or_payments', 0)
                ->where('delivery.reported_in_google_ads', 'not_verified'));
        $this->assertDatabaseHas('spectra_conversion_events', ['event' => 'paid_setup', 'mode' => 'verified_purchase', 'browser_invoked_at' => null]);
    }
}
