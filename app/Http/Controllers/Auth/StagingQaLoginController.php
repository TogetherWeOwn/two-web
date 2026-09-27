<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Staging-only sign-in for two deterministic QA identities.
 *
 * Cloudflare Access remains the outer gate. This inner token exists so an
 * authorised QA run can exercise the application's real session and Filament
 * authorisation paths without holding a reusable human Discord credential.
 */
class StagingQaLoginController
{
    public const HEADER = 'X-TWO-QA-Auth';

    /**
     * The signed-off moderator ID, from `services.discord.sysop_role_id` — the
     * same reference `discord:check-moderators` compares the grant against. The
     * qa-moderator fixture holds this role so the deterministic sign-in
     * exercises the real moderator path, and the value lives in one place
     * (config/services.php) instead of drifting as a second hard-coded copy.
     */
    private function sysopId(): string
    {
        return (string) config('services.discord.sysop_role_id');
    }

    /**
     * Built at runtime rather than as a class constant because the moderator
     * fixture holds the configured SySOp ID, and config is not available in a
     * constant expression.
     *
     * @return array<string, array{discord_id: string, username: string, display_name: string, roles: list<string>}>
     */
    private function identities(): array
    {
        return [
            'qa-member' => [
                'discord_id' => '900000000000001396',
                'username' => 'qa-member',
                'display_name' => 'QA Member',
                'roles' => [],
            ],
            'qa-moderator' => [
                'discord_id' => '900000000000001397',
                'username' => 'qa-moderator',
                'display_name' => 'QA Moderator',
                'roles' => [$this->sysopId()],
            ],
        ];
    }

    public function __invoke(Request $request, string $identity): Response
    {
        $presentedToken = (string) $request->header(self::HEADER, '');
        $request->headers->remove(self::HEADER);
        unset($_SERVER['HTTP_X_TWO_QA_AUTH']);

        abort_unless(app()->environment('staging'), 404);

        $configuredToken = (string) config('services.staging_qa_auth.token', '');

        // Check the secret before resolving the fixture. Missing identities and bad
        // credentials are therefore byte-identical. Hashing first gives hash_equals
        // fixed-size inputs as well as avoiding a character-by-character timing oracle.
        // Remove the header before anything can throw so exception reporters cannot
        // capture it from the request. A blank configured token always fails closed.
        $matches = hash_equals(
            hash('sha256', $configuredToken, binary: true),
            hash('sha256', $presentedToken, binary: true),
        );

        if ($configuredToken === '' || ! $matches) {
            abort(404);
        }

        $fixture = $this->identities()[$identity] ?? null;

        if ($fixture === null) {
            abort(404);
        }

        $roles = $fixture['roles'];
        $user = User::query()->updateOrCreate(
            ['discord_id' => $fixture['discord_id']],
            [
                'username' => $fixture['username'],
                'display_name' => $fixture['display_name'],
                'avatar' => null,
                'is_moderator' => in_array($this->sysopId(), $roles, strict: true),
                'discord_joined_at' => '2024-01-01 00:00:00',
                'discord_synced_at' => now(),
            ],
        );

        // The same session guard used by DiscordLoginController. The moderator then
        // reaches /admin only if User::canAccessPanel() accepts the stored role result.
        Auth::login($user);

        return response('', 204);
    }
}
