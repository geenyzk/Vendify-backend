<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days of audit trail to keep. The `audit:prune` command (run
    | daily by the scheduler) deletes anything older. The log is append-only
    | and cannot be deleted from the UI, so this is the only thing that trims
    | the table — set it to satisfy whatever retention policy you need.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),

];
