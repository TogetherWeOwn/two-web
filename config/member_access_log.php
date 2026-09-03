<?php

return [

    /*
     | How long a record of "who looked at member data" is kept.
     |
     | Ninety days is long enough that something noticed late can still be
     | investigated, and short enough that we are not maintaining a permanent
     | index of who read what. Shorter is a legitimate choice with a reason;
     | longer needs one too, in the other direction. Pruning runs daily —
     | routes/console.php.
     */
    'retention_days' => (int) env('MEMBER_ACCESS_LOG_RETENTION_DAYS', 90),

    /*
     | What happens when the log cannot be written.
     |
     | True: the read is refused. The panel shows an error and no member data
     | leaves the server unrecorded.
     |
     | This defaults to true in every environment, including local, on purpose.
     | An enforcement switch that is off by default is off in the one place it
     | mattered, and a developer who never sees it fail closed will not know that
     | it does. Turning it off is a decision to serve member data with no record
     | of who read it, and if that is ever the right call it should be an obvious
     | line in an environment file rather than a default nobody chose.
     */
    'enforce' => filter_var(env('MEMBER_ACCESS_LOG_ENFORCE', true), FILTER_VALIDATE_BOOL),

];
