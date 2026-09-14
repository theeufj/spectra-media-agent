/**
 * Group image collateral rows into the pictures they actually are.
 *
 * One generated scene is stored once per ad format — square for feeds,
 * 1200x628 for landscape placements, 300x250 for display — and the collateral
 * page rendered a card per row. Campaign 40 generated 8 scenes and showed 24
 * cards, so every creative appeared three times in three aspect ratios with
 * nothing saying they were the same photograph. A set that was already too
 * similar looked far worse than it was, and nobody reviews 24 tiles.
 *
 * Rows written before concept_key existed carry none. Each of those stands
 * alone rather than being guessed into a group: merging unrelated pictures
 * under one card is a worse error than showing an old row on its own.
 */

/** Shown on the card. Square is the one the scene was composed for. */
const PREFERRED_FORMAT = 'square';

export const FORMAT_LABELS = {
    square: '1024×1024',
    landscape: '1200×628',
    mrec: '300×250',
};

export function groupConcepts(images) {
    const groups = [];
    const byKey = new Map();

    (images || []).forEach((image) => {
        // A null key is unique to its row, so old rows never merge together.
        const key = image.concept_key || `row:${image.id}`;

        if (! byKey.has(key)) {
            const group = { key, images: [] };
            byKey.set(key, group);
            groups.push(group);
        }

        byKey.get(key).images.push(image);
    });

    return groups.map((group) => {
        const cover = group.images.find(i => i.format === PREFERRED_FORMAT) || group.images[0];

        return {
            key: group.key,
            cover,
            images: group.images,
            ids: group.images.map(i => i.id),
            formats: group.images.map(i => i.format).filter(Boolean),
            // Deployed when every size is, so a half-toggled concept reads as
            // off rather than silently deploying two of its three sizes.
            deployed: group.images.every(i => Boolean(i.should_deploy)),
        };
    });
}
