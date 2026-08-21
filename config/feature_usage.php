<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recording kill-switch
    |--------------------------------------------------------------------------
    |
    | Set FEATURE_USAGE_ENABLED=false to stop writing feature_usage_daily rows
    | without a deploy. This code runs inside user-facing requests, including
    | /dashboard, so the ability to turn it off from the environment is the
    | insurance that makes it safe to leave on.
    |
    | Reads are unaffected: the dashboard still reports whatever was recorded
    | before it was switched off.
    |
    */

    'enabled' => env('FEATURE_USAGE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days of history kept by `model:prune` (see routes/console.php). 400 rather
    | than 365 so a year-over-year comparison still has a prior-year figure to
    | compare against.
    |
    */

    'retention_days' => (int) env('FEATURE_USAGE_RETENTION_DAYS', 400),

];
