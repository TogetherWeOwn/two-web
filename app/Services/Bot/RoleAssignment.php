<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;

/**
 * One `role.assign` call, validated at construction.
 *
 * **`roleKey` is a key, never a Discord snowflake.** The bot holds the map, and
 * the set of assignable keys starts from the roles a member can already give
 * themselves in the onboarding menu — so handing this action to the website
 * grants no privilege a member does not already have by clicking. Passing a role
 * ID here does not work and is not meant to: it comes back `action_not_allowed`.
 *
 * `discordId` is the one field worth checking locally. A snowflake is decimal
 * digits, and the bot's answer to anything else is a `malformed` that names the
 * field but arrives a network round trip later, from a queue worker, in a log
 * nobody is reading. Checked here it is an exception in the request that built
 * it, pointing at the caller.
 *
 * There is no idempotency key on this action and that is the contract, not an
 * omission — see the class docblock on InternalActionClient.
 */
final readonly class RoleAssignment
{
    public function __construct(
        public string $discordId,
        public string $roleKey,
    ) {
        $this->validate();
    }

    /** @return array<string, string> */
    public function toPayload(): array
    {
        return [
            'action' => 'role.assign',
            'discord_id' => $this->discordId,
            'role_key' => $this->roleKey,
        ];
    }

    private function validate(): void
    {
        // Up to 20 digits: a snowflake is a 64-bit integer, so it never has more,
        // and the width is worth pinning because a truncated id is still digits.
        if (preg_match('/^\d{1,20}$/', $this->discordId) !== 1) {
            throw new InvalidActionRequestException(
                'A role.assign needs a Discord snowflake for discord_id: decimal digits, at most 20 of them.'
            );
        }

        if (trim($this->roleKey) === '') {
            throw new InvalidActionRequestException(
                'A role.assign needs a role_key. It is a key from the bot role map, not a Discord role id.'
            );
        }
    }
}
