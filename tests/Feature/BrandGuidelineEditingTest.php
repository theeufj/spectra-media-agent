<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BrandGuidelineEditingTest extends TestCase
{
    use DatabaseTransactions;

    private function profile(): array
    {
        return [
            'brand_voice' => ['primary_tone' => 'Professional', 'description' => 'Clear and practical.', 'examples' => ['More time with clients.']],
            'tone_attributes' => ['Helpful', 'Direct'],
            'color_palette' => ['primary_colors' => ['#123456'], 'secondary_colors' => [], 'description' => 'Navy and white', 'usage_notes' => 'Use clear contrast.'],
            'typography' => ['heading_style' => 'Sans serif', 'body_style' => 'Readable', 'fonts_detected' => ['Inter'], 'font_weights' => 'Regular', 'letter_spacing' => 'Normal'],
            'visual_style' => ['overall_aesthetic' => 'Clean', 'imagery_style' => 'Photography', 'description' => 'Real service activity', 'color_treatment' => 'Natural', 'layout_preference' => 'Simple'],
            'messaging_themes' => ['Property-specific advertising', 'Local targeting'],
            'unique_selling_propositions' => ['Ad copy from listing details'],
            'target_audience' => ['primary' => 'Real estate agents', 'demographics' => 'Agency teams', 'psychographics' => 'Value their time', 'pain_points' => ['Campaign administration'], 'language_level' => 'Professional', 'familiarity_assumption' => 'Intermediate'],
            'brand_personality' => ['archetype' => 'Expert', 'characteristics' => ['Helpful', 'Practical'], 'if_brand_were_person' => 'An experienced colleague'],
            'competitor_differentiation' => ['Written around each listing'],
            'do_not_use' => ['Guaranteed results'],
        ];
    }

    private function ownedGuideline(): BrandGuideline
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id]);

        return BrandGuideline::create(array_merge($this->profile(), ['customer_id' => $customer->id, 'extracted_at' => now()]));
    }

    public function test_saving_the_editor_payload_preserves_fields_needed_for_generation(): void
    {
        $brand = $this->ownedGuideline();
        $payload = $this->profile();
        $payload['target_audience']['demographics'] = 'Agents and agency owners, without an age restriction';
        $this->put(route('brand-guidelines.update', $brand), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $saved = $brand->fresh();
        foreach ($payload as $field => $value) {
            $this->assertSame($value, $saved->{$field}, $field.' was changed or dropped');
        }
        $this->assertTrue($saved->user_verified);
        $prompt = $saved->getFormattedGuidelines();
        $this->assertStringContainsString('Real estate agents', $prompt);
        $this->assertStringContainsString('Helpful, Practical', $prompt);
        $this->assertStringContainsString('Navy and white', $prompt);
    }

    public function test_a_partial_section_edit_preserves_other_fields_but_lists_can_be_cleared(): void
    {
        $brand = $this->ownedGuideline();
        $this->put(route('brand-guidelines.update', $brand), [
            'target_audience' => ['demographics' => 'Updated audience'],
            'tone_attributes' => [], 'messaging_themes' => [], 'competitor_differentiation' => [],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $saved = $brand->fresh();
        $this->assertSame('Real estate agents', $saved->target_audience['primary']);
        $this->assertSame('Professional', $saved->target_audience['language_level']);
        $this->assertSame('Updated audience', $saved->target_audience['demographics']);
        $this->assertSame([], $saved->tone_attributes);
        $this->assertSame([], $saved->messaging_themes);
        $this->assertSame([], $saved->competitor_differentiation);
    }

    public function test_previously_incomplete_profiles_can_still_be_used_in_generation(): void
    {
        $brand = new BrandGuideline([
            'target_audience' => ['demographics' => 'Agency owners'],
            'brand_personality' => ['archetype' => 'Expert'],
            'color_palette' => ['primary_colors' => null, 'secondary_colors' => ['#123456']],
        ]);
        $prompt = $brand->getFormattedGuidelines();
        $this->assertStringContainsString('Agency owners', $prompt);
        $this->assertStringContainsString('#123456', $prompt);
        $this->assertStringNotContainsString('Guaranteed results', $prompt);
    }
}
