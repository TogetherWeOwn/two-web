<?php

namespace App\Services\Bot;

use RuntimeException;

/**
 * The bot's URL, key id or shared secret is missing.
 *
 * Its own class because it is the one failure here that retrying cannot fix,
 * and because it is the *expected* state of any environment where the staging
 * credentials have not landed yet (TWO-21, TWO-11). A run against a box with a
 * blank `BOT_SHARED_SECRET` should say so in one line, not present as five
 * attempts and a `401`.
 */
class InternalActionMisconfigured extends RuntimeException
{
    public static function missing(string $key): self
    {
        return new self("services.bot.{$key} is not set — the website cannot sign a call to the bot without it.");
    }
}
