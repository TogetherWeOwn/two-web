<?php

namespace App\Http\Controllers;

use App\Services\Bot\BotClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as DiscordUser;
use RuntimeException;
use Throwable;

/**
 * One-click join: a visitor presses a button, approves Discord once, and is in
 * the TWO server. No invite link, no second step, no "now go and click this".
 *
 * This is the only working web-to-Discord conversion path TWO has, and it works
 * on the WordPress site we are retiring. Shipping a plain invite link in its
 * place would be a downgrade at the front door, which is the whole reason this
 * exists (TOG-80).
 *
 * Two credentials are needed in the same Discord request and no one service
 * holds both: the bot token, which lives in the bot process, and this member's
 * OAuth token with the `guilds.join` scope, which only the website can obtain.
 * So the website gets the token and the bot makes the call. That split is the
 * design, not an accident of who built what.
 *
 * **Separate journey from login, deliberately.** `guilds.join` is a line on a
 * consent screen that says "add you to servers", and every line on a consent
 * screen is a reason to press Cancel. A returning member checking their profile
 * must never be asked for it. See App\Http\Controllers\Auth\DiscordLoginController,
 * which asks for `identify` and `guilds.members.read` and nothing else.
 *
 * **It degrades, it does not break.** Routing through the bot means one-click is
 * only up while the bot is up. Every failure here — bot down, action switched
 * off, Discord refusing, member cancelling — ends at the same plain invite link.
 * Losing one-click is a downgrade; losing the click is losing the recruit.
 */
class JoinController
{
    /**
     * `identify` gives us the member's Discord id, which the bot needs to name
     * them. `guilds.join` is the permission to add them. There is no third scope
     * that earns its place on this screen: we do not read their roles here,
     * because being added is not signing in — they sign in afterwards, through
     * the login journey, which asks for what it needs then.
     */
    private const SCOPES = ['identify', 'guilds.join'];

    public function __construct(private readonly BotClient $bot) {}

    /** The join page, and where every outcome below lands. */
    public function show(): View
    {
        return view('join', [
            'oneClick' => $this->bot->configured(),
            'inviteUrl' => $this->inviteUrl(),
        ]);
    }

    /** Send them to Discord to approve being added. */
    public function redirect(): RedirectResponse
    {
        // Asking for `guilds.join` when we cannot act on it spends the member's
        // consent for nothing and lands them on the invite link anyway. If
        // one-click is not available, say so now — the page they land on shows
        // the invite link, which is the same place they were going to end up.
        if (! $this->bot->configured()) {
            return $this->done('unavailable');
        }

        // setScopes, not scopes: scopes() *merges* with the driver's defaults and
        // the Discord driver defaults to asking for `email`. Same trap as login.
        return $this->discord()
            ->setScopes(self::SCOPES)
            ->redirectUrl($this->callbackUrl())
            ->redirect();
    }

    /** Discord sends them back here, either with a code or with a complaint. */
    public function callback(Request $request): RedirectResponse
    {
        // They pressed Cancel on the Discord consent screen. Not an error, a no.
        if ($request->filled('error')) {
            return $this->done('denied');
        }

        try {
            // redirectUrl again, and this is not redundant: Discord requires the
            // `redirect_uri` on the token exchange to match the one sent on the
            // authorize step exactly. Omit it here and the driver falls back to
            // the *login* callback from config, the exchange is rejected, and
            // every join dies at this line — a failure that only shows up against
            // real Discord, never against a stub.
            $discordUser = $this->discord()
                ->redirectUrl($this->callbackUrl())
                ->user();
        } catch (Throwable $e) {
            // A stale or replayed callback, a bad state token, a refused code
            // exchange. From the member's side these are all "start again".
            Log::warning('Discord token exchange failed on the join journey.', [
                'exception' => $e->getMessage(),
            ]);

            return $this->done('expired');
        }

        // Same reason as discord() below: the contract does not promise an access
        // token, and the token is the entire point of this journey.
        if (! $discordUser instanceof DiscordUser) {
            throw new RuntimeException('The Discord driver returned an unexpected user object.');
        }

        $result = $this->bot->addMember(
            (string) $discordUser->getId(),
            (string) $discordUser->token,
        );

        $outcome = $result->outcome;

        // The token's life ends on the line above. It is not flashed to the
        // session, not stored, and not passed to the view — the redirect below
        // carries an outcome string and nothing else.
        //
        // Every failure collapses to one word here on purpose. Which of them it
        // was is the log's business, and the client has already written it with
        // the bot's request id; a visitor needs the way in, not a diagnosis.
        return $this->done($outcome === null ? 'unavailable' : $outcome->value);
    }

    /**
     * The Discord driver, narrowed to the OAuth2 provider it actually is.
     *
     * Socialite's container returns the generic contract, which promises neither
     * setScopes() nor an access token on the user. If this fails, the driver
     * registration in AppServiceProvider is gone — a deploy problem rather than a
     * member problem, so it throws instead of apologising to a visitor about a
     * broken installation.
     */
    private function discord(): AbstractProvider
    {
        $driver = Socialite::driver('discord');

        if (! $driver instanceof AbstractProvider) {
            throw new RuntimeException('The Discord Socialite driver is not registered.');
        }

        return $driver;
    }

    /**
     * Built from the route, so the route and what we tell Discord cannot drift
     * apart. `/join/callback` is registered as a redirect URI on the OAuth
     * application for all three origins we serve — Discord compares character
     * for character and stops the member at its own error screen on a mismatch,
     * where we cannot even apologise. tests/Feature/Join/JoinRedirectUriTest.php
     * pins both halves.
     */
    private function callbackUrl(): string
    {
        return route('join.callback');
    }

    /**
     * The plain invite link, and the honest floor under this whole feature.
     *
     * Null when it is not configured, which is a deploy mistake rather than
     * something a member can fix — the page then says so rather than rendering a
     * link to nowhere.
     */
    private function inviteUrl(): ?string
    {
        $url = trim((string) config('services.discord.invite_url'));

        return $url === '' ? null : $url;
    }

    private function done(string $result): RedirectResponse
    {
        return redirect()->route('join')->with('join_result', $result);
    }
}
