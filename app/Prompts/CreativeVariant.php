<?php

namespace App\Prompts;

/**
 * Give each image or video slot a different selling angle, even when an upstream
 * splitter repeats a scene. Preserve the approved offer and visual treatment.
 */
class CreativeVariant
{
    /** @var list<string|null> Slot zero preserves the briefed concept. */
    public const LENSES = [
        null,

        'VARIATION FOR THIS CREATIVE — where this conflicts with the scene above, this wins: '.
        'BENEFIT IN ACTION. Show a concrete, desirable use or outcome supported by this offer. '.
        'If an Alternative angle is supplied, use that concept. Change the focal action and context, '.
        'not just the camera angle; the product or service benefit must be recognisable at thumbnail size. '.
        'Keep the subject prominent; no distant establishing view. Do not invent results or claims. '.
        'Preserve the brand palette, visual medium and placement constraints; people are optional.',

        'VARIATION FOR THIS CREATIVE — where this conflicts with the scene above, this wins: '.
        'DEMONSTRATION OR DETAIL. Make one supported feature, material or service action the hero. '.
        'Use a tight, purposeful composition showing how it works or what makes it distinctive. '.
        'For an intangible service, show a concrete action already supported by the brief; do not invent a physical product. '.
        'Preserve the palette, visual medium and placement constraints. Hands appear only if they explain the benefit; '.
        'no obligatory face, paperwork or device, and no fabricated interface or evidence.',

        'VARIATION FOR THIS CREATIVE — where this conflicts with the scene above, this wins: '.
        'DISTINCT USE OCCASION. Show another situation the supplied offer explicitly serves. '.
        'Change the action and environment, retaining a prominent product or service subject and the brand treatment. '.
        'Do not merely recast the same person or invent an audience, feature or customer result.',

        'VARIATION FOR THIS CREATIVE — where this conflicts with the scene above, this wins: '.
        'OBJECT-LED HERO. Build a bold, tactile composition around the actual product or objects central to the service. '.
        'No people; deliberate light, material texture and brand colour make the selling idea clear. '.
        'Stay within the approved medium and placement constraints; do not substitute decorative props for the offer.',
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
            $index === 1 => 'benefit — a desirable use or outcome',
            $index === 2 => 'demonstration — a feature or service action',
            $index === 3 => 'occasion — another supported use',
            $index === 4 => 'hero — the product or service objects',
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
