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

        // What creative generation actually ran last — the configured model
        // can differ from what history shows if the env changed recently.
        $lastImageGeneration = \App\Models\AiCost::where('operation', 'generateImage')
            ->orWhere('model', 'like', '%image%')
            ->latest()
            ->first(['model', 'created_at', 'cost']);

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'campaignModeDescription' => \App\Services\CampaignStatusHelper::getModeDescription(),
            'imagePromptDefault' => \App\Prompts\ImagePrompt::defaultTemplate(),
            'imagePromptCustom' => (string) Setting::get(\App\Prompts\ImagePrompt::TEMPLATE_SETTING, ''),
            'imageModel' => config('ai.image_provider', 'grok') === 'grok'
                ? config('ai.models.image_grok').' via OpenRouter (fallback: '.config('ai.models.image').')'
                : config('ai.models.image'),
            'lastImageGeneration' => $lastImageGeneration,
            'adCopyDirectives' => (string) Setting::get(\App\Prompts\AdCopyPrompt::DIRECTIVES_SETTING, ''),
            'adCopyModel' => config('ai.models.default'),
            'videoPromptDefault' => \App\Prompts\VideoFromScriptPrompt::defaultTemplate(),
            'videoPromptCustom' => (string) Setting::get(\App\Prompts\VideoFromScriptPrompt::TEMPLATE_SETTING, ''),
            'videoModel' => config('ai.video_provider', 'grok') === 'grok'
                ? config('ai.models.video_grok').' via OpenRouter (fallback: '.config('ai.models.video').')'
                : config('ai.models.video'),
            'lastVideoGeneration' => \App\Models\AiCost::where('operation', 'startVideoGeneration')
                ->latest()
                ->first(['model', 'created_at', 'cost']),
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
            'image_prompt_template' => 'sometimes|nullable|string|max:20000',
            'video_prompt_template' => 'sometimes|nullable|string|max:20000',
            'ad_copy_directives' => 'sometimes|nullable|string|max:10000',
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

        // Creative generation prompt — blank means "use the built-in default".
        // Saving an edit that strips every placeholder would generate images
        // with no brand or strategy input, so require at least the strategy.
        if ($request->has('image_prompt_template')) {
            $template = trim((string) $request->input('image_prompt_template'));

            if ($template !== '' && ! str_contains($template, '{{creative_strategy}}')) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'The creative prompt must include the {{creative_strategy}} placeholder — without it every image ignores the campaign it was generated for.',
                ]);
            }

            Setting::set(\App\Prompts\ImagePrompt::TEMPLATE_SETTING, $template, 'string', 'Custom creative generation prompt (blank = built-in default)');
        }

        // Video prompt — same rules: blank = default, and the visuals must
        // follow the narration, so the script placeholder is required.
        if ($request->has('video_prompt_template')) {
            $template = trim((string) $request->input('video_prompt_template'));

            if ($template !== '' && ! str_contains($template, '{{voiceover_script}}')) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'The video prompt must include the {{voiceover_script}} placeholder — without it the visuals have no connection to the narration.',
                ]);
            }

            Setting::set(\App\Prompts\VideoFromScriptPrompt::TEMPLATE_SETTING, $template, 'string', 'Custom video generation prompt (blank = built-in default)');
        }

        // Ad copy house style — additive directives, no placeholders needed.
        if ($request->has('ad_copy_directives')) {
            Setting::set(\App\Prompts\AdCopyPrompt::DIRECTIVES_SETTING, trim((string) $request->input('ad_copy_directives')), 'string', 'House style directives injected into every ad copy prompt');
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Settings updated successfully.',
        ]);
    }
}
