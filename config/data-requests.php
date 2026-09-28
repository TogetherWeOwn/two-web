<?php

return [

    /*
     | How long a closed data request (approved or rejected) is kept.
     |
     | Ninety days by default, matching the member-data access log: the queue
     | row is the audit trail of the decision, and it lives as long as the log
     | that says who looked. Pending rows are never pruned — an open ask is
     * live work, not history. Pruning runs daily — routes/console.php.
     */
    'retention_days' => (int) env('DATA_REQUEST_RETENTION_DAYS', 90),

];
