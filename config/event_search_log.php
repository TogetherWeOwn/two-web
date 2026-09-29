<?php

return [

    /*
     | How long a record of "what guests searched for on /events" is kept.
     |
     | Ninety days is long enough to spot content gaps across a season of game
     | nights, and short enough that we are not maintaining a permanent index
     | of what people looked for. Matches the member-data access log default.
     | Shorter is a legitimate choice with a reason; longer needs one too, in
     | the other direction. Pruning runs daily — routes/console.php.
     */
    'retention_days' => (int) env('EVENT_SEARCH_LOG_RETENTION_DAYS', 90),

];
