<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as DiscordUser;
use RuntimeException;
use Throwable;

/**
 * Signing in with Discord is the only way in. There is no password anywhere in
 * this application, which is why there is no password column.
 *
 * The one rule that shapes this class: a member who cannot sign in must always be
 * told why in a sentence. Discord is a third party we do not control, so every
 * step here assumes it can refuse, stall, or disappear.
 */
class DiscordLoginController
{
    /**
     * Two scopes, and there is no third we can justify. `identify` is who they
     * are; `guilds.members.read` is their roles in TWO, which is the only thing
     * that decides what they can see here.
     *
     * Not `email`: no shipped feature sends mail, so we do not ask.
     * Not `guilds`: that lists every server they are in, and we never call the
     * endpoint it unlocks — the per-guild member lookup below needs only
     * `guilds.members.read`.
     * Not `guilds.join`: adding somebody to the server is a separate journey with
     * its own consent screen, not a tax on every returning member's login.
     */
    private const SCOPES = ['identify', 'guilds.members.read'];

    /** Send the member to Discord to approve us. */
    public function redirect(): RedirectResponse
    {
        // setScopes, not scopes: scopes() *merges* with the driver's defaults, and
        // the Discord driver defaults to asking for `email`. We have no feature
        // that uses an email address, so we must not ask for one.
        return $this->discord()->setScopes(self::SCOPES)->redirect();
    }

    /** Discord sends them back here, either with a code or with a complaint. */
    public function callback(Request $request): RedirectResponse
    {
        // They pressed Cancel on the Discord consent screen. Not an error, just a no.
        if ($request->filled('error')) {
            return $this->failed('denied');
        }

        try {
            $discordUser = $this->discord()->user();
        } catch (Throwable $e) {
            // A stale or replayed callback, a bad state token, a refused code
            // exchange. From the member's side these are all "start again".
            Log::warning('Discord token exchange failed.', ['exception' => $e->getMessage()]);

            return $this->failed('expired');
        }

        // Same reason as discord() above: the contract does not promise an access
        // token, and we need one to ask Discord about their roles.
        if (! $discordUser instanceof DiscordUser) {
            throw new RuntimeException('The Discord driver returned an unexpected user object.');
        }

        $member = $this->guildMember((string) $discordUser->token);

        if ($member === 'not_a_member' || $member === 'unavailable') {
            return $this->failed($member);
        }

        $user = $this->upsert($discordUser, $member);

        // Auth::login migrates the session id for us, which is what closes session
        // fixation. There is a test asserting that so we notice if it ever changes.
        Auth::login($user, remember: true);

        return redirect()->intended(route('profile'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * The Discord driver, narrowed to the OAuth2 provider it actually is.
     *
     * Socialite's container returns the generic contract, which promises neither
     * setScopes() nor an access token on the user. If this ever fails, the driver
     * registration in AppServiceProvider is gone and no member can sign in — that
     * is a deploy problem, not a member problem, so it throws rather than showing
     * somebody a friendly message about a broken installation.
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
     * This member's membership of the TWO server, read with their own token.
     *
     * We ask Discord rather than the bot on purpose: sign-in has to keep working
     * when the bot is down, and it means the front door does not wait on the bot's
     * read-only views to exist.
     *
     * @return array<string, mixed>|'not_a_member'|'unavailable'
     */
    private function guildMember(string $accessToken): array|string
    {
        $guildId = (string) config('services.discord.guild_id');

        if ($guildId === '') {
            // Misconfiguration, not a member problem. Fail closed and shout in the log.
            Log::error('services.discord.guild_id is not set; nobody can sign in.');

            return 'unavailable';
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('services.discord.timeout', 5))
                ->retry(2, 200, throw: false)
                ->get(sprintf(
                    '%s/users/@me/guilds/%s/member',
                    rtrim((string) config('services.discord.api_base'), '/'),
                    $guildId,
                ));
        } catch (Throwable $e) {
            Log::warning('Discord guild member lookup did not answer.', ['exception' => $e->getMessage()]);

            return 'unavailable';
        }

        // Discord says "Unknown Guild" for both "no such server" and "you are not in
        // it". From here the honest message is the second one.
        if ($response->status() === 404) {
            return 'not_a_member';
        }

        if ($response->failed()) {
            Log::warning('Discord guild member lookup failed.', ['status' => $response->status()]);

            return 'unavailable';
        }

        return (array) $response->json();
    }

    /**
     * Create or refresh the member's record. Discord is the source of truth for
     * every field written here, so every field here is overwritten on every login.
     * Anything the member writes about themselves lives on `profiles` and is never
     * touched by this method.
     *
     * @param  array<string, mixed>  $member
     */
    private function upsert(DiscordUser $discordUser, array $member): User
    {
        $raw = $discordUser->getRaw();

        return User::query()->updateOrCreate(
            ['discord_id' => (string) $discordUser->getId()],
            [
                'username' => (string) $discordUser->getNickname(),
                'display_name' => $raw['global_name'] ?? null,
                'avatar' => $discordUser->getAvatar(),
                'is_moderator' => $this->isModerator($member['roles'] ?? []),
                'discord_joined_at' => isset($member['joined_at'])
                    ? Carbon::parse($member['joined_at'])
                    : null,
                'discord_synced_at' => now(),
            ],
        );
    }

    /**
     * Roles are matched by ID, never by name, because names get renamed and a
     * renamed role must not silently hand out or take away the admin panel.
     *
     * @param  array<int, string>  $roles
     */
    private function isModerator(array $roles): bool
    {
        $moderatorRoles = (array) config('services.discord.moderator_role_ids', []);

        // No configured moderator role means nobody is a moderator. Fail closed.
        if ($moderatorRoles === []) {
            return false;
        }

        return array_intersect($roles, $moderatorRoles) !== [];
    }

    private function failed(string $code): RedirectResponse
    {
        return redirect()->route('home')->with('auth_error', $code);
    }
}
