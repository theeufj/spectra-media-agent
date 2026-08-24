<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Prompts\ImagePrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The admin-editable creative generation prompt: quality can be tuned from
 * the portal without a deploy, and a broken edit can't silently detach
 * generation from the campaign it serves.
 */
class CreativePromptSettingTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->roles()->attach($role);

        return $admin;
    }

    public function test_generation_uses_the_default_template_when_no_override_exists(): void
    {
        $prompt = (new ImagePrompt('Show boots in workshop light.'))->getPrompt();

        $this->assertStringContainsString('Show boots in workshop light.', $prompt);
        $this->assertStringContainsString('DESIGN REQUIREMENTS', $prompt);
        $this->assertStringNotContainsString('{{creative_strategy}}', $prompt);
    }

    public function test_an_admin_override_replaces_the_default(): void
    {
        Setting::set(ImagePrompt::TEMPLATE_SETTING, "Cinematic photography only.\n{{creative_strategy}}", 'string');

        $prompt = (new ImagePrompt('Show boots.'))->getPrompt();

        $this->assertStringContainsString('Cinematic photography only.', $prompt);
        $this->assertStringContainsString('Show boots.', $prompt);
        $this->assertStringNotContainsString('DESIGN REQUIREMENTS', $prompt);
    }

    public function test_admin_can_save_and_reset_the_template_from_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'deployment_enabled' => true,
            'image_prompt_template' => "Bold flat illustration style.\n{{creative_strategy}}",
        ])->assertRedirect();

        $this->assertStringContainsString('Bold flat illustration', (string) Setting::get(ImagePrompt::TEMPLATE_SETTING));

        // Blank resets to the built-in default.
        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'deployment_enabled' => true,
            'image_prompt_template' => '',
        ])->assertRedirect();

        $this->assertSame('', (string) Setting::get(ImagePrompt::TEMPLATE_SETTING));
        $this->assertSame(ImagePrompt::defaultTemplate(), ImagePrompt::activeTemplate());
    }

    public function test_video_generation_uses_default_then_override(): void
    {
        $prompt = (new \App\Prompts\VideoFromScriptPrompt('Fast-paced product shots.', 'Welcome to Acme.'))->getPrompt();
        $this->assertStringContainsString('Welcome to Acme.', $prompt);
        $this->assertStringContainsString('VISUAL STORYTELLING', $prompt);
        $this->assertStringNotContainsString('{{voiceover_script}}', $prompt);

        Setting::set(\App\Prompts\VideoFromScriptPrompt::TEMPLATE_SETTING, "Documentary style.\n{{voiceover_script}}", 'string');
        $prompt = (new \App\Prompts\VideoFromScriptPrompt('x', 'Welcome to Acme.'))->getPrompt();
        $this->assertStringContainsString('Documentary style.', $prompt);
        $this->assertStringContainsString('Welcome to Acme.', $prompt);
    }

    public function test_video_template_without_script_placeholder_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.update'), [
            'deployment_enabled' => true,
            'video_prompt_template' => 'Just pretty scenes.',
        ])->assertRedirect();

        $this->assertSame('', (string) Setting::get(\App\Prompts\VideoFromScriptPrompt::TEMPLATE_SETTING, ''));
    }

    public function test_ad_copy_directives_are_injected_when_set(): void
    {
        $prompt = (new \App\Prompts\AdCopyPrompt('Sell boots.', 'Google Ads'))->getPrompt();
        $this->assertStringNotContainsString('HOUSE STYLE DIRECTIVES', $prompt);

        Setting::set(\App\Prompts\AdCopyPrompt::DIRECTIVES_SETTING, 'Australian English spelling. Never say "unlock".', 'string');

        $prompt = (new \App\Prompts\AdCopyPrompt('Sell boots.', 'Google Ads'))->getPrompt();
        $this->assertStringContainsString('HOUSE STYLE DIRECTIVES', $prompt);
        $this->assertStringContainsString('Never say "unlock"', $prompt);
        // The machine contract survives the injection.
        $this->assertStringContainsString('RESPONSE FORMAT', $prompt);
    }

    public function test_a_template_without_the_strategy_placeholder_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'deployment_enabled' => true,
            'image_prompt_template' => 'Just make something pretty.',
        ])->assertRedirect();

        // Not saved — generation without the strategy would ignore every
        // campaign it runs for.
        $this->assertSame('', (string) Setting::get(ImagePrompt::TEMPLATE_SETTING, ''));
    }
}
