/**
 * The chart palette for the admin analytics pages.
 *
 * Validated, not eyeballed. Every number below came from the dataviz validator
 * (OKLab ΔE ×100) against the surface these charts actually render on — the
 * admin cards are `bg-white`, so light was checked at #ffffff, not a warm
 * off-white:
 *
 *   light, adjacent pairs   worst CVD ΔE 9.1 (yellow↔aqua)   normal 19.6   PASS
 *   dark,  adjacent pairs   worst CVD ΔE 8.4 (yellow↔aqua)   normal 19.3   PASS
 *
 * SLOT ORDER IS THE SAFETY MECHANISM, NOT DECORATION. It is what keeps every
 * neighbouring pair separable under colour-vision deficiency. Assign slots in
 * order and never cycle: an 8th series folds into "Other" or becomes small
 * multiples. Colour follows the entity, never its rank — a filter that drops a
 * series must not repaint the survivors.
 *
 * ALL-PAIRS FORMS ARE CAPPED AT THREE. Scatter, bubble and small multiples put
 * every series beside every other, not just its neighbours. The first three
 * slots clear the all-pairs gates in both modes (worst CVD ΔE 11.1 light /
 * 12.0 dark). The fourth is fine in light but collides with flame in dark
 * (CVD ΔE 0.7 — indistinguishable), so past three, facet instead.
 *
 * THE LIGHT CONTRAST WARN IS NOT DISMISSABLE. aqua (2.82:1), magenta (2.69:1)
 * and yellow (2.17:1) sit below 3:1 on white. The relief is mandatory: every
 * chart using those slots ships visible direct labels or the table beside it.
 * That is why the dashboard pairs each chart with its own table rather than
 * treating tables as a nice-to-have.
 */

// Slot 1 is the brand flame-orange (#ff4d00, flame-orange-500) — the primary
// series, and the only slot with a fixed meaning.
export const CATEGORICAL = {
    light: ['#ff4d00', '#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7', '#008300'],
    dark:  ['#ff4d00', '#3987e5', '#199e70', '#c98500', '#d55181', '#9085e9', '#008300'],
};

/** Series cap for forms where every pair is visible at once (scatter, bubble, small multiples). */
export const ALL_PAIRS_CAP = 3;

/**
 * Status colours are RESERVED. Never reuse one as "series 6" — a red mark that
 * means "series" and a red mark that means "critical" on the same page makes
 * both unreadable. Fixed across modes, and always shipped with an icon or a
 * label: status never rides on hue alone.
 *
 * Note `serious` is an orange and sits close to the flame series slot. Where
 * they appear together, the label carries the distinction — not the hue.
 */
export const STATUS = {
    good: '#0ca30c',
    warning: '#fab219',
    serious: '#ec835a',
    critical: '#d03b3b',
};

/** Recessive chrome. Grid and axis stay behind the data, never compete with it. */
export const INK = {
    light: {
        surface: '#ffffff',
        primary: '#0b0b0b',
        secondary: '#52514e',
        muted: '#898781',
        grid: '#e1e0d9',
        axis: '#c3c2b7',
    },
    dark: {
        surface: '#1a1a19',
        primary: '#ffffff',
        secondary: '#c3c2b7',
        muted: '#898781',
        grid: '#2c2c2a',
        axis: '#383835',
    },
};

/**
 * A single sequential hue, light → dark, for continuous magnitude (the cohort
 * retention grid). One hue only — a rainbow ramp has no readable order.
 *
 * For ORDINAL marks (discrete ordered steps) start no lighter than index 2:
 * the first two steps recede into a white surface, which is correct for "near
 * zero" in a heatmap and wrong for a labelled tier.
 */
export const SEQUENTIAL_BLUE = [
    '#cde2fb', '#b7d3f6', '#86b6ef', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95',
];

/** Lowest ordinal step that still clears 2:1 on a white surface. */
export const SEQUENTIAL_ORDINAL_FLOOR = 2;

/**
 * Slot colours in order. `count` is the number of series, so callers get a
 * stable prefix rather than a cycled list.
 */
export function seriesColors(count, mode = 'light') {
    const slots = CATEGORICAL[mode] ?? CATEGORICAL.light;

    if (count > slots.length) {
        // Loud rather than silent: cycling would give two series the same colour.
        console.warn(
            `[palette] ${count} series requested but only ${slots.length} slots exist. ` +
            'Fold the tail into "Other" or use small multiples — do not cycle.',
        );
    }

    return slots.slice(0, count);
}

/** One slot by index, for charts that name their series explicitly. */
export function seriesColor(index, mode = 'light') {
    const slots = CATEGORICAL[mode] ?? CATEGORICAL.light;

    return slots[index] ?? INK[mode].muted;
}

/** Semi-transparent fill for area charts, so the line above it stays readable. */
export function fillFor(hex, alpha = 0.12) {
    const n = parseInt(hex.slice(1), 16);

    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}
