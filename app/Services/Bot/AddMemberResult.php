<?php

namespace App\Services\Bot;

use App\Enums\JoinOutcome;

final readonly class AddMemberResult
{
    private function __construct(
        public ?JoinOutcome $outcome,
        public ?InternalActionFailure $failure,
        public string $requestId,
    ) {}

    public static function succeeded(JoinOutcome $outcome, string $requestId): self
    {
        return new self($outcome, null, $requestId);
    }

    public static function failed(InternalActionFailure $failure): self
    {
        return new self(null, $failure, $failure->requestId);
    }
}
