<?php

namespace App\Support\Profiles;

use Illuminate\Support\Carbon;

final readonly class MemberStats
{
    /**
     * @param  list<Milestone>  $milestones
     */
    private function __construct(
        public string $discordId,
        public bool $available,
        public ?Carbon $joinedAt,
        public ?int $tenureDays,
        public ?string $rankKey,
        public ?bool $isCurrentMember,
        public array $milestones,
    ) {}

    /**
     * @param  list<Milestone>  $milestones
     */
    public static function available(
        string $discordId,
        ?Carbon $joinedAt,
        ?int $tenureDays,
        ?string $rankKey,
        bool $isCurrentMember,
        array $milestones,
    ): self {
        return new self(
            discordId: $discordId,
            available: true,
            joinedAt: $joinedAt,
            tenureDays: $tenureDays,
            rankKey: $rankKey,
            isCurrentMember: $isCurrentMember,
            milestones: $milestones,
        );
    }

    public static function unavailable(string $discordId): self
    {
        return new self(
            discordId: $discordId,
            available: false,
            joinedAt: null,
            tenureDays: null,
            rankKey: null,
            isCurrentMember: null,
            milestones: [],
        );
    }
}
