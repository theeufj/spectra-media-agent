<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off by default. These chains write to people who are not yet customers,
    | from a named founder's address, so nothing should reach a real prospect
    | before someone has read the copy. Turning this on is a deliberate act;
    | the machinery can be deployed and test-sent without it.
    |
    */

    'enabled' => (bool) env('EMAIL_SEQUENCES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Who hears about replies
    |--------------------------------------------------------------------------
    |
    | Everyone notified when a prospect writes back. Defaults to the admin role
    | so it cannot drift out of step with who actually runs the company, but
    | can be pinned to specific addresses here.
    |
    */

    'reply_notify' => array_filter(explode(',', (string) env('EMAIL_SEQUENCE_REPLY_NOTIFY', ''))),

    /*
    |--------------------------------------------------------------------------
    | Send ceiling per run
    |--------------------------------------------------------------------------
    |
    | A backstop, not a throttle. If an audience query is ever wrong, this is
    | the difference between a handful of misdirected emails and every address
    | in the database receiving one. Resend is rate limited separately.
    |
    */

    'max_per_run' => (int) env('EMAIL_SEQUENCE_MAX_PER_RUN', 200),

];
