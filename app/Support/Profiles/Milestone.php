<?php

namespace App\Support\Profiles;

use Illuminate\Support\Carbon;

final readonly class Milestone
{
    public function __construct(
        public string $type,
        public Carbon $occurredAt,
        public ?string $detail,
    ) {}
}
