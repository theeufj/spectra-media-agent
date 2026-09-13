/**
 * Is the active account a one-time setup engagement?
 *
 * A US$999 customer bought their way out of Google Ads: we build the account,
 * the tracking, the campaign and the ads, then hand it over. Every control that
 * asks them to build something themselves is an instruction to do the thing
 * they paid us to do — and the campaign wizard goes further, telling them "No
 * ad platform sub-accounts are set up yet. Contact us to get your account
 * configured", which is precisely the intimidation the fee exists to remove.
 *
 * Read from the shared active_customer rather than passed page by page, because
 * the controls that need it are in the app shell.
 */
export function isSetupOnly(page) {
    return page?.props?.auth?.user?.active_customer?.service_type === 'setup_only';
}
