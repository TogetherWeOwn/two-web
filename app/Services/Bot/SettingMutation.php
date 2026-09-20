<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;
use JsonException;

/** One `settings.set` call, including the admin Discord id used by the audit row. */
final readonly class SettingMutation
{
    private const MAX_VALUE_BYTES = 8192;

    public function __construct(
        public string $key,
        public mixed $value,
        public string $updatedBy,
    ) {
        $this->validate();
    }

    /** @return array{action: string, key: string, value: mixed, updated_by: string} */
    public function toPayload(): array
    {
        return [
            'action' => 'settings.set',
            'key' => $this->key,
            // Present even when null: null deliberately removes the stored row.
            'value' => $this->value,
            'updated_by' => $this->updatedBy,
        ];
    }

    private function validate(): void
    {
        // Keep this in step with two-bot's SETTINGS_KEY_PATTERN. The bot remains
        // the authority on which well-formed keys are actually storable.
        if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', $this->key) !== 1) {
            throw new InvalidActionRequestException(
                'A settings.set key must be an uppercase environment-variable name between 2 and 128 characters.'
            );
        }

        if (preg_match('/^\d{17,20}$/', $this->updatedBy) !== 1) {
            throw new InvalidActionRequestException(
                'A settings.set needs the signed-in admin Discord snowflake for updated_by.'
            );
        }

        try {
            $encoded = json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidActionRequestException('The setting value could not be encoded as JSON.', 0, $e);
        }

        if (strlen($encoded) > self::MAX_VALUE_BYTES) {
            throw new InvalidActionRequestException(
                'A settings.set value is at most '.self::MAX_VALUE_BYTES.' encoded bytes.'
            );
        }
    }
}
