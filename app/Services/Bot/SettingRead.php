<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;

/** One naturally-idempotent `settings.get` call. */
final readonly class SettingRead
{
    public function __construct(public string $key)
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', $this->key) !== 1) {
            throw new InvalidActionRequestException(
                'A settings.get key must be an uppercase environment-variable name between 2 and 128 characters.'
            );
        }
    }

    /** @return array{action: string, key: string} */
    public function toPayload(): array
    {
        return [
            'action' => 'settings.get',
            'key' => $this->key,
        ];
    }
}
