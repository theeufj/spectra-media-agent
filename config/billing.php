<?php

return [

    /*
     * Early-exit terms for managed customers whose campaigns we built inside
     * an account THEY own (bring-your-own-account links). The build has a
     * price — the one-time setup fee — and it's included only after this
     * many paid months. Leaving earlier (cancelling, or revoking our
     * manager access) converts the engagement to the setup fee, less what
     * they've already paid in subscription.
     *
     * Nothing is auto-charged: an assessment is recorded and the admin is
     * emailed the computed amount with the trigger. Collection is a human
     * decision, made against the ToS clause, not a surprise card charge.
     */
    'early_exit' => [
        'minimum_months' => (int) env('EARLY_EXIT_MINIMUM_MONTHS', 3),
    ],

    /*
     * Stripe-metered ad-spend billing — a second, mutually exclusive model to
     * the managed prepaid credit system (ProcessDailyAdSpendBilling /
     * AdSpendCredit), which is authoritative. Off by default so the two can
     * never charge the same spend twice; billing:report-ad-spend returns
     * immediately while it is.
     *
     * These keys were read by that command but defined nowhere, so the flag was
     * false because it was absent rather than because it was set — the same
     * shape of silence that hid a deleted config block. Declared here so the
     * setting is a decision on the page rather than an omission.
     */
    'metered_ad_spend_enabled' => (bool) env('BILLING_METERED_AD_SPEND_ENABLED', false),

    // Stripe meter event name. Required only when the flag above is on.
    'ad_spend_meter' => env('BILLING_AD_SPEND_METER'),

];
