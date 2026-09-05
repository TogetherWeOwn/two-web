<?php

namespace App\Support\Counts;

/**
 * How much we trust the number we are about to publish.
 *
 * The bot's views already age a value out — `human_member_count` becomes null
 * after 24 hours without a successful read, and that ceiling lives in the view
 * so a collector that *stopped* ages its own numbers out (two-bot
 * `docs/WEBSITE_CONTRACT.md` §3, §5). So by the time a row reaches us there are
 * only two questions left: is the value there at all, and is it recent enough
 * to print without a timestamp beside it.
 *
 * `Unavailable` is not an error. It is the expected answer whenever the
 * collector has been dark for a day, and it is also the answer on a completely
 * empty database — which is what production looked like on the day this page
 * was built. The page renders it as designed prose, never as `0`.
 */
enum CountsFreshness
{
    /** Read within the last 10 minutes. Print the number plainly. */
    case Fresh;

    /** Older than 10 minutes but inside the view's ceiling. Print it with "as of HH:MM". */
    case Stale;

    /** No value. Omit the number entirely — see LiveCounts. */
    case Unavailable;
}
