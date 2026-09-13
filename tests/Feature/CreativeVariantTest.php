<?php

namespace Tests\Feature;

use App\Prompts\CreativeVariant;
use PHPUnit\Framework\TestCase;

/**
 * A set of creatives has to look like a set, not like one picture printed
 * several times.
 *
 * The splitter is asked for three distinct scenes and told in as many words
 * that paraphrasing is a failure. It returns three versions of the same
 * picture anyway: a campaign whose strategy named a potter, a male creator at a
 * desk and hands packing a box came back as the same woman in the same studio
 * holding the same tablet, three times over, across two separate regenerations.
 *
 * Asking a model to vary its own output is a request. This is the guarantee:
 * each slot carries a lens that changes what the camera is doing and who is in
 * front of it, stated as an override so it wins wherever it contradicts the
 * scene. Three identical scenes through three lenses are still three different
 * photographs.
 */
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

    public function test_the_lenses_change_more_than_the_camera_angle(): void
    {
        $all = implode(' ', array_filter(CreativeVariant::LENSES));

        /*
           "The same person in the same room from a different angle" is the
           failure mode, so the set has to reach for distance, for detail, for a
           different person, and for no person at all.
        */
        $this->assertStringContainsString('pull much further back', $all);
        $this->assertStringContainsString('no face in shot', $all);
        $this->assertStringContainsString('different person', $all);
        $this->assertStringContainsString('no people at all', $all);
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
        $this->assertStringContainsString('pull much further back', $asked[1]);
        $this->assertStringContainsString('no face in shot', $asked[2]);
    }
}
