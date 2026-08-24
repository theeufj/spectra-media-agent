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
        $this->assertStringContainsString('TECHNICAL SPECIFICATIONS', $prompt);
        $this->assertStringNotContainsString('{{creative_strategy}}', $prompt);
    }

    public function test_an_admin_override_replaces_the_default(): void
    {
        Setting::set(ImagePrompt::TEMPLATE_SETTING, "Cinematic photography only.\n{{creative_strategy}}", 'string');

        $prompt = (new ImagePrompt('Show boots.'))->getPrompt();

        $this->assertStringContainsString('Cinematic photography only.', $prompt);
        $this->assertStringContainsString('Show boots.', $prompt);
        $this->assertStringNotContainsString('TECHNICAL SPECIFICATIONS', $prompt);
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
