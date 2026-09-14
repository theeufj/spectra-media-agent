<?php

namespace App\Prompts;

/**
 * One deliberate difference per creative in a set.
 *
 * The splitter is asked for three distinct scenes and told that paraphrasing is
 * a failure. It still returns three versions of the same picture: a campaign
 * whose strategy named a potter, a male creator at a desk, and hands packing a
 * box came back as the same woman in the same studio holding the same tablet,
 * three times. Asking a model to vary its own output is a request, and a
 * request is not a guarantee.
 *
 * So the variation is structural instead. Each slot in the set carries a lens —
 * a short directive that changes what the camera is doing and who is in front
 * of it — and the lens is stated as an override, so it wins wherever it
 * contradicts the scene it is applied to. Three identical scenes through these
 * three lenses are still three different photographs, which is the property
 * that matters: a person scrolling should not meet the same ad twice.
 *
 * The first slot is deliberately empty. The strategy's own scene is the one
 * that was briefed and approved, and it should appear as written at least once.
 */
class CreativeVariant
{
    /**
     * Applied by position. Index 0 is the scene as briefed.
     *
     * Each lens changes at least two of subject, distance and framing, because
     * changing one is how "a different camera angle" passes for variety and
     * produces the set we already had.
     *
     * @var list<string|null>
     */
    public const LENSES = [
        null,

        'VARIATION FOR THIS IMAGE — where this conflicts with the scene above, this wins: '.
        'step back to take in the whole room and its light, from across that room rather than across the street. '.
        'The person stays clearly readable at roughly a third of the frame; the space around them is the point. '.
        'Stay inside the room — no exteriors, no shooting through a window or doorway from outside.',

        'VARIATION FOR THIS IMAGE — where this conflicts with the scene above, this wins: '.
        'move in close on hands and the object they are working with — no face in shot at all. '.
        'Shallow depth of field, the work itself filling the frame.',

        'VARIATION FOR THIS IMAGE — where this conflicts with the scene above, this wins: '.
        'cast a different person from the one described. Change their age, their gender and the room they are in, '.
        'while keeping the same trade, the same warmth and the same time of day.',

        'VARIATION FOR THIS IMAGE — where this conflicts with the scene above, this wins: '.
        'no people at all. The finished work, the tools and the workspace, photographed on their own in natural light.',
    ];

    /**
     * The scene a given slot should actually be generated from.
     *
     * Slots beyond the lens list fall back to the scene as briefed rather than
     * wrapping around, because a repeated lens produces a repeated picture and
     * that is the whole thing this exists to prevent.
     */
    public static function apply(string $scene, int $index): string
    {
        $lens = self::LENSES[$index] ?? null;

        return $lens === null ? $scene : $scene."\n\n".$lens;
    }

    /**
     * A short name for the lens on a given slot, for logs.
     *
     * The lens is the thing that decides whether a set looks like a set, so
     * when a customer says "these all look the same" the log has to be able to
     * answer which lens each creative was generated under.
     */
    public static function label(int $index): string
    {
        // Keyed on the slot rather than on the lens text, so renaming a lens
        // cannot silently turn every label into 'unknown'.
        return match (true) {
            ! isset(self::LENSES[$index]) => 'none — the scene as briefed',
            $index === 1 => 'wide — the place is the subject',
            $index === 2 => 'close — hands and the work, no face',
            $index === 3 => 'recast — a different person',
            $index === 4 => 'still life — no people',
            default => 'slot '.$index,
        };
    }

    /**
     * How many visibly different creatives this can produce before it starts
     * repeating itself. Callers sizing a set should not ask for more.
     */
    public static function count(): int
    {
        return count(self::LENSES);
    }
}
