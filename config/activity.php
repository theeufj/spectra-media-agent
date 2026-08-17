<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity log retention
    |--------------------------------------------------------------------------
    |
    | How many days of admin activity stay queryable in the database. Older days
    | are written to storage as one gzipped JSONL file each and then removed —
    | write, verify, then delete, so a failed upload leaves the rows in place.
    |
    | Archives are never deleted by this platform. They are the record of who
    | changed what, and expiring them is a decision for a storage lifecycle
    | policy, made deliberately, rather than a side effect of a nightly job.
    |
    */

    'retention_days' => env('ACTIVITY_LOG_RETENTION_DAYS', 30),

];
