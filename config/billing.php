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

];
