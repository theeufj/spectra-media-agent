/**
 * @typedef {{id: number, cloudfront_url?: string, status?: string, format?: string}} CreativeAsset
 * @typedef {{adCopy: object|null, imageCollaterals: CreativeAsset[], videoCollaterals: CreativeAsset[], collateralErrors?: {message: string}[]}} CollateralSnapshot
 */

/** @param {unknown} value @returns {CollateralSnapshot} */
export function parseCollateral(value) {
    if (!value || typeof value !== 'object'
        || !Array.isArray(value.imageCollaterals) || !Array.isArray(value.videoCollaterals)
        || (value.adCopy != null && typeof value.adCopy !== 'object')
        || (value.collateralErrors != null && !Array.isArray(value.collateralErrors))) {
        throw new Error('The creative status response was incomplete.');
    }
    return value;
}

/** @param {number|string} value @returns {number} Exact cents for form previews. */
export function budgetCents(value) {
    if (!/^\d+(\.\d{1,2})?$/.test(String(value))) throw new Error('Enter a budget with at most two decimal places.');
    const [whole, fraction = ''] = String(value).split('.');
    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(cents) || cents <= 0) throw new Error('Enter a positive daily budget.');
    return cents;
}
