<?php

namespace App\Services\Bot;

/** A successful `settings.set`; the value is intentionally not echoed by the bot. */
final readonly class SettingWriteResult
{
    public function __construct(
        public string $requestId,
        public string $key,
        public SettingWriteOutcome $outcome,
        public bool $replayed,
    ) {}
}
