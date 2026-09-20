<?php

namespace App\Services\Bot;

/** A successful `settings.get`; unset never reveals the environment fallback. */
final readonly class SettingReadResult
{
    public function __construct(
        public string $requestId,
        public string $key,
        public mixed $value,
        public SettingSource $source,
    ) {}
}
