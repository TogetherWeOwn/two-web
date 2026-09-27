<?php

namespace App\Http\Controllers;

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use App\Models\User;
use App\Services\Bot\Exceptions\BotException;
use App\Services\Bot\InternalActionClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as DiscordUser;
use RuntimeException;
use Throwable;

/** The one-click web-to-Discord join journey. */
final class JoinController
{
    private const SCOPES = ['identify', 'guilds.join'];

    public function __construct(private readonly InternalActionClient $bot) {}

    public function show(): View
    {
        return view('join', ['inviteUrl' => $this->inviteUrl()]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->botConfigured()) {
            $this->recordAttempt(JoinOutcome::Degraded, null, null, null);

            return $this->done('unavailable');
        }

        $this->rememberSource($request);

        return $this->discord()
            ->setScopes(self::SCOPES)
            ->redirectUrl($this->callbackUrl())
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $this->recordAttempt(JoinOutcome::Denied, null, null, null);

            return $this->done('denied');
        }

        try {
            $discordUser = $this->discord()
                ->redirectUrl($this->callbackUrl())
                ->user();
        } catch (Throwable $exception) {
            $deniedSource = $request->session()->pull('join_source');

            Log::warning('Discord token exchange failed on the join journey.', [
                'exception' => $exception::class,
            ]);

            $this->recordAttempt(JoinOutcome::Error, is_string($deniedSource) ? $deniedSource : null, null, null);

            return $this->done('expired');
        }

        if (! $discordUser instanceof DiscordUser) {
            $unexpectedSource = $request->session()->pull('join_source');

            $this->recordAttempt(JoinOutcome::Error, is_string($unexpectedSource) ? $unexpectedSource : null, null, null);

            throw new RuntimeException('The Discord driver returned an unexpected user object.');
        }

        try {
            $result = $this->bot->addMember(
                (string) $discordUser->getId(),
                (string) $discordUser->token,
            );
        } catch (BotException $exception) {
            $source = $request->session()->pull('join_source');

            Log::warning('One-click join could not reach the bot contract.', [
                'exception' => $exception::class,
                'source' => $source,
            ]);

            $this->recordAttempt(JoinOutcome::Degraded, is_string($source) ? $source : null, null, $this->safeDiscordId($discordUser));

            return $this->done('unavailable');
        }

        $source = $request->session()->pull('join_source');
        $discordId = $this->safeDiscordId($discordUser);

        if ($result->outcome === null) {
            Log::warning('One-click join fell back to the invite.', [
                'source' => $source,
                'code' => $result->failure?->code,
                'request_id' => $result->requestId,
            ]);

            $this->recordAttempt(JoinOutcome::Degraded, is_string($source) ? $source : null, $result->requestId !== '' ? $result->requestId : null, $discordId);

            return $this->done('unavailable');
        }

        Log::info('One-click join succeeded.', [
            'source' => $source,
            'outcome' => $result->outcome->value,
            'request_id' => $result->requestId,
        ]);

        $this->recordAttempt($result->outcome, is_string($source) ? $source : null, $result->requestId !== '' ? $result->requestId : null, $discordId);

        // Join never writes `is_moderator`: only login recomputes it from Discord
        // roles, and join's scopes (identify + guilds.join) cannot read roles.
        // Writing `false` here would demote a returning moderator until their
        // next login. New rows fall back to the column default (false).
        $user = User::query()->updateOrCreate(
            ['discord_id' => (string) $discordUser->getId()],
            [
                'username' => (string) $discordUser->getNickname(),
                'display_name' => $discordUser->getRaw()['global_name'] ?? null,
                'avatar' => $discordUser->getAvatar(),
                'discord_synced_at' => now(),
            ],
        );

        Auth::login($user);

        return redirect()->route('profile')->with('join_result', $result->outcome->value);
    }

    private function discord(): AbstractProvider
    {
        $driver = Socialite::driver('discord');

        if (! $driver instanceof AbstractProvider) {
            throw new RuntimeException('The Discord Socialite driver is not registered.');
        }

        return $driver;
    }

    private function callbackUrl(): string
    {
        return route('join.callback');
    }

    private function inviteUrl(): string
    {
        $url = config('services.discord.invite_url');

        return is_string($url) && $url !== '' ? $url : DiscordInviteController::FALLBACK_INVITE;
    }

    private function botConfigured(): bool
    {
        try {
            $this->bot->assertConfigured();

            return true;
        } catch (BotException) {
            return false;
        }
    }

    private function rememberSource(Request $request): void
    {
        $source = $request->query('source');

        if (is_string($source) && preg_match('/^[a-z0-9][a-z0-9:_-]{0,63}$/i', $source) === 1) {
            $request->session()->put('join_source', $source);
        }
    }

    private function done(string $result): RedirectResponse
    {
        return redirect()->route('join')->with('join_result', $result);
    }

    /**
     * One queryable row per terminal join path (TOG-5617), next to the log
     * line that stays the human-readable trail.
     *
     * Only the four safe columns ever reach the table: outcome, source,
     * request_id, discord_id. The OAuth token lives in the signed bot request
     * body and the stack frame only; exception messages can quote it; Discord's
     * error_description is attacker-shaped. None of them is a parameter here,
     * so none of them can end up in the row.
     */
    private function recordAttempt(JoinOutcome $outcome, ?string $source, ?string $requestId, ?string $discordId): void
    {
        JoinAttempt::query()->create([
            'outcome' => $outcome,
            'source' => $source,
            'request_id' => $requestId !== '' ? $requestId : null,
            'discord_id' => $discordId !== '' ? $discordId : null,
        ]);
    }

    /**
     * The Discord id when there is one to record, never the token.
     *
     * Takes the Socialite user (or anything unexpected the driver handed
     * back) and returns only the id string — the one join key the funnel
     * needs. Anything without a readable id records null rather than a dump.
     */
    private function safeDiscordId(mixed $discordUser): ?string
    {
        if (! $discordUser instanceof DiscordUser) {
            return null;
        }

        $id = (string) $discordUser->getId();

        return $id !== '' ? $id : null;
    }
}
