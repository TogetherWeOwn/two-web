<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `/discord` — the front door, and the only web-to-Discord conversion path TWO
 * has (TOG-77). The live WordPress apex answers this today and it is the only
 * link on that homepage, so whatever serves the apex after the cutover has to
 * answer it from the first second. A 404 here is a total funnel outage.
 *
 * The rule this class exists to keep, from docs/dns.md: **a member who clicked
 * that link must always end up in Discord, even when the invite lookup, the
 * database or the bot is unavailable.** So:
 *
 *   - no database, no cache, no bot call, no session — see routes/funnel.php,
 *     which registers this outside the `web` middleware group on purpose;
 *   - every read is wrapped, and anything at all going wrong lands on
 *     {@see self::FALLBACK_INVITE} rather than on an error page;
 *   - the destination is checked to be a Discord URL before we send anyone to
 *     it, so a mistyped `DISCORD_INVITE_URL` cannot turn our best-known link
 *     into an open redirect pointed at someone else's site.
 *
 * What this deliberately is *not*: the one-click `guilds.join` flow the
 * WordPress page performs today, where the member approves Discord and is in
 * the server without ever seeing an invite page. That needs the bot token and
 * the member's token in the same request, so it is a two-service journey and it
 * is **TOG-80**, owned by the Founding Engineer. When it lands it replaces
 * {@see self::destination()}'s happy path and nothing else here changes — the
 * fallback below is exactly what TOG-80 needs to fall back *to*.
 */
class DiscordInviteController
{
    /**
     * The last resort, hardcoded on purpose.
     *
     * This is the `WEB-HOMEPAGE` campaign code from TOG-96 — created never
     * expiring, unlimited uses, `temporary: false`, precisely so the website's
     * join button has an invite that cannot rot and so web arrivals attribute
     * to the website instead of to `unknown`.
     *
     * It is duplicated in `config/services.php` as the default for
     * `services.discord.invite_url`, which is the value normally served. That
     * duplication is deliberate: config is the knob, this constant is what
     * answers when reading the knob fails. It is not left to memory —
     * `tests/Feature/DiscordFunnelTest.php` fails the build if the two ever
     * stop matching, so rotating the invite in one place and not the other is
     * a red suite rather than a fallback that quietly points at a dead code.
     *
     * An invite code is not a secret: it is a public join link, it is meant to
     * be published, and it grants nothing but membership of a server anyone can
     * ask to join. Rotating it is one line here, one line in config, one commit.
     */
    public const FALLBACK_INVITE = 'https://discord.gg/4GwEDNRTtx';

    /**
     * Where a redirect out of this controller is allowed to land. An exact host
     * match, not a suffix match — `discord.gg.example.com` ends with
     * `discord.gg` and is not Discord.
     *
     * @var list<string>
     */
    private const DISCORD_HOSTS = ['discord.gg', 'discord.com'];

    public function __invoke(): RedirectResponse
    {
        // 302, not 301. TOG-80 changes where this lands, and a 301 is cached by
        // browsers effectively forever — we would have no way to move the door
        // for the people who had already used it. `no-store` for the same
        // reason at the edge: this is the one link we must be able to retarget
        // in a minute, so nothing gets to remember it for us.
        return redirect()
            ->away($this->destination(), 302)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The configured invite if it is usable, and the hardcoded one otherwise.
     *
     * Nothing in here is allowed to throw. A member clicking the join link is
     * the single most valuable request this application serves, and an
     * exception page is worse than an invite that is one rotation out of date.
     */
    private function destination(): string
    {
        try {
            $configured = config('services.discord.invite_url');

            if (is_string($configured) && $this->landsInDiscord($configured)) {
                return $configured;
            }

            // Not a member problem and not something they can retry into
            // working, so it is logged loudly and they still get sent to
            // Discord. The funnel keeps running while we fix the config.
            Log::error('services.discord.invite_url is unusable; serving the hardcoded fallback invite.', [
                'configured' => is_string($configured) ? $configured : gettype($configured),
            ]);
        } catch (Throwable $e) {
            Log::error('Could not read services.discord.invite_url; serving the hardcoded fallback invite.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return self::FALLBACK_INVITE;
    }

    private function landsInDiscord(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        // https only: this link gets pasted into stream titles and DMs, and we
        // are not the ones who downgrade it.
        return ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::DISCORD_HOSTS, true);
    }
}
