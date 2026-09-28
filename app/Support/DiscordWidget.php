<?php

namespace App\Support;

/**
 * The Discord server widget embed for /join (TOG-6928).
 *
 * A pure builder over `services.discord.guild_id` — no query, no auth, no HTTP.
 * The controller owns the page; this owns the iframe src, so a change to the
 * embed contract has one place to land.
 *
 * Null when the guild id is missing or not a snowflake, and the page renders
 * without the iframe rather than pointing one at a URL Discord would 404:
 * the static fallback (invite link + what-to-expect copy) is always in the
 * HTML, so the page converts with or without the widget.
 */
final class DiscordWidget
{
    public static function url(?string $guildId): ?string
    {
        $guildId = trim((string) $guildId);

        // Snowflakes are 17-20 digits. Anything else is a misconfigured value
        // that must never reach an iframe src — a short/long digit run would
        // build a URL Discord 404s instead of rendering no iframe at all.
        if (! preg_match('/\A[0-9]{17,20}\z/', $guildId)) {
            return null;
        }

        return 'https://discord.com/widget?id='.$guildId.'&theme=dark';
    }
}
