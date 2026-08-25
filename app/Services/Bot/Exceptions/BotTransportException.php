<?php

namespace App\Services\Bot\Exceptions;

use Throwable;

/**
 * We asked, and got back nothing we can act on.
 *
 * Two shapes, both of which mean "there is no `retryable` flag to obey and no
 * `request_id` to log": the bot was unreachable or too slow, or something
 * answered that is not the documented envelope — a reverse proxy error page, a
 * half-deployed bot, a truncated body.
 *
 * Both are worth retrying, but that judgement belongs to the caller's backoff
 * policy (TOG-52c) rather than to a flag we would have to invent here. What
 * matters is that this is distinguishable from BotNotConfiguredException, which
 * is terminal, and from an InternalActionFailure, which is the bot's own answer.
 */
final class BotTransportException extends BotException
{
    public static function unreachable(string $url, Throwable $previous): self
    {
        // $previous carries the underlying message; the URL is a config value and
        // holds no credential, so both are safe to surface.
        return new self("The bot internal action endpoint at {$url} could not be reached.", 0, $previous);
    }

    public static function unreadable(string $why, int $status): self
    {
        return new self("The bot internal action endpoint answered {$status} with something that is not the v0.3 envelope: {$why}.");
    }
}
