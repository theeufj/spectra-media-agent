import { usePage } from '@inertiajs/react';

/**
 * The active customer's currency, for anything that renders money.
 *
 * Pages wrote `${value.toFixed(2)}` with a hardcoded dollar sign — 216 such
 * calls across 64 files. Nine of seventeen production customers are on AUD and
 * one is on a code that is not ISO 4217 at all, so the majority of customers
 * were reading their own spend, budgets and forecasts labelled as US dollars.
 *
 * HandleInertiaRequests already shares the active customer on every response,
 * so the code is on the wire; nothing was reading it. Pair this with
 * `money()` from utils/format, which disambiguates by the viewer's locale: an
 * AU viewer sees "$" for AUD and "USD" for USD, a US viewer sees the reverse,
 * so the symbol always answers "which dollars is this?".
 *
 * Falls back to USD, which is the column default and what every one of those
 * hardcoded dollar signs silently assumed.
 */
export function useCurrency() {
    const { auth } = usePage().props;

    return auth?.user?.active_customer?.currency_code || 'USD';
}
