<?php

namespace App\Support\Testing;

use SocialiteProviders\Discord\Provider;

/**
 * Discord-shaped OAuth provider whose endpoints live on the local Dusk stub.
 *
 * The class is only registered when DUSK_TEST_SEAMS is enabled. Keeping the
 * override in a provider rather than in the controller means the browser still
 * exercises Socialite's state, code exchange and user mapping exactly as the
 * production journey does.
 */
class DiscordProvider extends Provider
{
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoint('/oauth2/authorize'), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoint('/oauth2/token');
    }

    /** @return array<string, mixed> */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->endpoint('/users/@me'), [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.discord.test_provider_url'), '/').$path;
    }
}
