/**
 * The one card surface.
 *
 * 43 card recipes shipped across the app; `Campaigns/Show.jsx` alone puts four
 * corner radii on a single scroll (8px, 12px, 16px, 6px). This component
 * existed the whole time and was imported by zero files, partly because its
 * recipe — `shadow-sm sm:rounded-lg` — was not the one anybody wanted:
 *
 *  - `sm:rounded-lg` (52 uses) means SQUARE CORNERS BELOW 640px. On a phone a
 *    full-bleed square box sat directly under the rounded KPI cards it was
 *    meant to match. The radius here is unconditional.
 *  - `rounded-xl border border-gray-200 shadow-sm` is the most common
 *    deliberate recipe in the codebase (30 uses, including the Dashboard KPI
 *    row), so converting toward it moves the fewest screens.
 *
 * Shadow deeper than `shadow-sm` is reserved for things that float — modals,
 * popovers, dropdowns. A card sits on the page; it does not hover above it.
 */

/**
 * @param {object} props
 * @param {React.ReactNode} props.children
 * @param {string|false} [props.padding] Tailwind padding for the card's own
 *   box. Pass `false` for a card whose child bleeds to the edge (a full-width
 *   table, a cover image) — and in that case add `overflow-hidden` via
 *   `className` so the child respects the radius, plus an inner
 *   `overflow-x-auto` around any table, or the table is clipped rather than
 *   scrollable.
 * @param {string} [props.className] Layout and overrides — grid placement,
 *   spacing, a status-coloured border.
 * @param {React.ElementType} [props.as] Element to render, for cards that are
 *   semantically a `section` or an `li`.
 */
export default function Card({ children, padding = 'p-6', className = '', as: Tag = 'div', ...props }) {
    return (
        <Tag
            className={`bg-white rounded-xl border border-gray-200 shadow-sm ${padding || ''} ${className}`}
            {...props}
        >
            {children}
        </Tag>
    );
}
