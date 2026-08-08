<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Global platform settings.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class SettingsController extends Controller
{
    public function settingsIndex()
    {
        $settings = Setting::all();

        // Ensure boost pack defaults exist for the UI
        $boostDefaults = [
            'creative_boost_price_cents' => ['value' => 2900, 'type' => 'integer', 'description' => 'Creative Boost Pack price in cents'],
            'creative_boost_image_generations' => ['value' => 25, 'type' => 'integer', 'description' => 'Image generations per boost pack'],
            'creative_boost_video_generations' => ['value' => 5, 'type' => 'integer', 'description' => 'Video generations per boost pack'],
            'creative_boost_refinements' => ['value' => 25, 'type' => 'integer', 'description' => 'Refinements per boost pack'],
        ];

        $existingKeys = $settings->pluck('key')->toArray();
        foreach ($boostDefaults as $key => $meta) {
            if (! in_array($key, $existingKeys)) {
                $settings->push(new Setting(['key' => $key, 'value' => (string) $meta['value'], 'type' => $meta['type'], 'description' => $meta['description']]));
            }
        }

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'campaignModeDescription' => \App\Services\CampaignStatusHelper::getModeDescription(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'deployment_enabled' => 'required|boolean',
            'campaign_testing_mode' => 'sometimes|boolean',
            'managed_billing_enabled' => 'sometimes|boolean',
            'creative_boost_price_cents' => 'sometimes|integer|min:100',
            'creative_boost_image_generations' => 'sometimes|integer|min:0',
            'creative_boost_video_generations' => 'sometimes|integer|min:0',
            'creative_boost_refinements' => 'sometimes|integer|min:0',
        ]);

        Setting::set('deployment_enabled', $request->deployment_enabled, 'boolean');

        // Store campaign testing mode in database
        if ($request->has('campaign_testing_mode')) {
            Setting::set('campaign_testing_mode', $request->campaign_testing_mode, 'boolean');
        }

        if ($request->has('managed_billing_enabled')) {
            Setting::set('managed_billing_enabled', $request->managed_billing_enabled, 'boolean');
        }

        // Creative Boost Pack settings
        foreach (['creative_boost_price_cents', 'creative_boost_image_generations', 'creative_boost_video_generations', 'creative_boost_refinements'] as $key) {
            if ($request->has($key)) {
                Setting::set($key, $request->integer($key), 'integer');
            }
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Settings updated successfully.',
        ]);
    }
}
