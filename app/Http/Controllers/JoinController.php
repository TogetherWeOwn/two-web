<?php

namespace App\Http\Controllers;

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use App\Models\User;
use App\Services\Bot\Exceptions\BotException;
use App\Services\Bot\InternalActionClient;
use App\Support\DiscordWidget;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
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
        // TOG-6928: the widget iframe is a live look, never the conversion
        // path. It renders beside the one-click button and the static invite,
        // and is absent entirely when the guild id is unusable — the fallback
        // copy is always in the HTML either way.
        $guildId = config('services.discord.guild_id');

        return view('join', [
            'inviteUrl' => $this->inviteUrl(),
            'widgetUrl' => DiscordWidget::url(is_string($guildId) ? $guildId : null),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->botConfigured()) {
            $this->rememberSource($request);
            $degradedSource = $request->session()->pull('join_source');

            $this->recordAttempt(JoinOutcome::Degraded, is_string($degradedSource) ? $degradedSource : null, null, null);

            return $this->done('unavailable');
        }

        $this->rememberSource($request);

        return $this->discord()
            ->setScopes(self::SCOPES)
            ->redirectUrl($this->callbackUrl())
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        if ($request->filled('error')) {
            $deniedSource = $request->session()->pull('join_source');

            $this->recordAttempt(JoinOutcome::Denied, is_string($deniedSource) ? $deniedSource : null, null, null);

            // They pressed Cancel on the Discord consent screen
            // (`access_denied`), or Discord answered the approval with an
            // error instead of a code. Either way there is nothing to
            // exchange, so this is a page that says what happened with one
            // button to try again — not a redirect whose banner is easy to
            // miss after a round trip to Discord and back.
            //
            // Discord's own `error_description` is never rendered: it is a
            // third-party string and not ours to echo.
            $denied = $request->query('error') === 'access_denied';

            // Same recovery page the Discord-down path renders: one retry
            // button plus the static invite fallback, so a member who
            // cancelled (or hit a provider error) always has a way in even
            // if the retry also fails. Wrapped like discordDown() so the
            // declared `RedirectResponse|Response` return type holds.
            return response()->view('oauth.recovery', [
                'title' => __('join.recovery_title'),
                'message' => $denied ? __('join.recovery_denied') : __('join.recovery_error'),
                'retryUrl' => route('join.redirect'),
                'retryLabel' => __('join.recovery_retry'),
                'inviteUrl' => $this->inviteUrl(),
            ]);
        }

        try {
            $discordUser = $this->discord()
                ->redirectUrl($this->callbackUrl())
                ->user();
        } catch (Throwable $exception) {
            $expired = $this->isExpiredApproval($exception);
            $tokenSource = $request->session()->pull('join_source');

            // Exception messages and response bodies can contain OAuth secrets.
            Log::warning('Discord token exchange failed on the join journey.', [
                'exception' => $exception::class,
                'source' => $tokenSource,
                'outcome' => $expired ? 'expired' : 'error',
            ]);

            $this->recordAttempt(JoinOutcome::Error, is_string($tokenSource) ? $tokenSource : null, null, null);

            return $expired ? $this->done('expired') : $this->discordDown();
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
            $botSource = $request->session()->pull('join_source');

            Log::warning('One-click join could not reach the bot contract.', [
                'exception' => $exception::class,
                'source' => $botSource,
            ]);

            $this->recordAttempt(JoinOutcome::Degraded, is_string($botSource) ? $botSource : null, null, $this->safeDiscordId($discordUser));

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

    private function isExpiredApproval(Throwable $exception): bool
    {
        if ($exception instanceof InvalidStateException) {
            return true;
        }

        // Socialite uses Guzzle for the token exchange. Only Discord's explicit
        // invalid_grant response means the code expired; other 4xx/5xx do not.
        if (! $exception instanceof ClientException || $exception->getResponse()->getStatusCode() !== 400) {
            return false;
        }

        $payload = json_decode((string) $exception->getResponse()->getBody(), true);

        return is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant';
    }

    private function discordDown(): Response
    {
        return response()->view('oauth.recovery', [
            'title' => __('join.recovery_discord_down_title'),
            'message' => __('join.recovery_discord_down'),
            'retryUrl' => route('join.redirect'),
            'retryLabel' => __('join.recovery_retry'),
            'inviteUrl' => $this->inviteUrl(),
        ], 503);
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
