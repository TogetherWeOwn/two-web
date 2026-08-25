<?php

namespace App\Services\Bot\Exceptions;

/**
 * We are not set up to talk to the bot at all.
 *
 * Deliberately its own type and not a transport error. A queued job (TOG-52c)
 * turns this into a terminal skip rather than a retry loop, and it cannot make
 * that distinction if a missing secret looks the same as a refused connection —
 * an unconfigured environment would otherwise spend its whole backoff schedule
 * discovering the same blank line in `.env` over and over.
 *
 * The message names the environment variable. It never quotes a value: the
 * point of this class is that the value is missing, and the one case where it is
 * present-but-wrong is a `unauthorized` from the bot, not this.
 */
final class BotNotConfiguredException extends BotException
{
    public static function missing(string $variable): self
    {
        return new self("The bot internal action endpoint is not configured: {$variable} is empty.");
    }
}
