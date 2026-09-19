<?php

namespace Tests\Feature;

use App\Prompts\CreativeVariant;
use PHPUnit\Framework\TestCase;

/** Different slots must test selling angles while preserving the supplied offer. */
class CreativeVariantTest extends TestCase
{
    private const SCENE = 'A boutique maker in a sunlit studio holding a tablet.';

    public function test_the_briefed_scene_survives_untouched(): void
    {
        // Whatever else the set contains, the scene that was written and
        // approved has to appear as written at least once.
        $this->assertSame(self::SCENE, CreativeVariant::apply(self::SCENE, 0));
    }

    public function test_identical_scenes_still_produce_different_instructions(): void
    {
        $rendered = array_map(
            fn ($i) => CreativeVariant::apply(self::SCENE, $i),
            range(0, CreativeVariant::count() - 1),
        );

        // The property that matters: feed the same sentence into every slot and
        // no two slots ask for the same picture.
        $this->assertSame(
            count($rendered),
            count(array_unique($rendered)),
            'two slots would generate the same image from the same scene',
        );
    }

    public function test_each_lens_overrides_rather_than_decorates(): void
    {
        for ($i = 1; $i < CreativeVariant::count(); $i++) {
            $applied = CreativeVariant::apply(self::SCENE, $i);

            // A scene naming a smiling woman and a lens asking for hands only
            // is a contradiction, and the model has to know which wins — or it
            // splits the difference and draws the same woman again.
            $this->assertStringContainsString('this wins', $applied, "lens {$i} does not override the scene");
            $this->assertStringContainsString(self::SCENE, $applied, "lens {$i} discarded the scene entirely");
        }
    }

    public function test_the_lenses_change_the_selling_angle_without_forcing_people_or_rooms(): void
    {
        $benefit = CreativeVariant::apply(self::SCENE, 1);
        $demonstration = CreativeVariant::apply(self::SCENE, 2);

        $this->assertStringContainsString('BENEFIT IN ACTION', $benefit);
        $this->assertStringContainsString('Alternative angle', $benefit);
        $this->assertStringContainsString('people are optional', $benefit);
        $this->assertStringContainsString('DEMONSTRATION OR DETAIL', $demonstration);
        $this->assertStringContainsString('do not invent a physical product', $demonstration);
        foreach ([$benefit, $demonstration] as $prompt) {
            $this->assertStringContainsString('placement constraints', $prompt);
            $this->assertStringContainsString('visual medium', $prompt);
            $this->assertStringNotContainsString('whole room', $prompt);
        }
    }

    public function test_a_slot_beyond_the_lenses_falls_back_rather_than_repeating(): void
    {
        // Wrapping around would hand two slots the same lens, which is the one
        // outcome this class exists to prevent.
        $this->assertSame(self::SCENE, CreativeVariant::apply(self::SCENE, CreativeVariant::count() + 3));
    }

    public function test_slots_map_to_different_lenses(): void
    {
        $scene = self::SCENE;

        // What the three dispatched jobs will actually ask for.
        $asked = array_map(fn ($slot) => CreativeVariant::apply($scene, $slot), [0, 1, 2]);

        $this->assertSame(3, count(array_unique($asked)));
        $this->assertStringNotContainsString('VARIATION', $asked[0]);
        $this->assertStringContainsString('BENEFIT IN ACTION', $asked[1]);
        $this->assertStringContainsString('DEMONSTRATION OR DETAIL', $asked[2]);
    }
}
