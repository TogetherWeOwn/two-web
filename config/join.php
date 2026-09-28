<?php

return [

    /*
     | How long a join-attempt funnel row is kept.
     |
     | Ninety days is long enough to see a funnel break that started weeks
     | ago, and short enough that a row per join attempt does not grow the
     | table forever. The admin funnel widget counts this table, so this
     | window is what the widget sees — it shows the retention window, not
     | all time. Pruning runs daily — routes/console.php.
     */
    'attempt_retention_days' => (int) env('JOIN_ATTEMPT_RETENTION_DAYS', 90),

];
