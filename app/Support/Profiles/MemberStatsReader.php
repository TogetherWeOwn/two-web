<?php

namespace App\Support\Profiles;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class MemberStatsReader implements MemberStatsSource
{
    public function __construct(private DatabaseManager $db) {}

    public function forMember(string $discordId): MemberStats
    {
        try {
            $connection = $this->db->connection('bot');
            $member = $connection->selectOne(
                'select member_id, joined_at, tenure_days, rank_key, is_current_member from web_v1.members where member_id = ? limit 1',
                [$discordId],
            );
            $milestoneRows = $member === null ? [] : $connection->select(
                'select milestone, occurred_at, detail from web_v1.member_milestones where member_id = ? order by occurred_at desc',
                [$discordId],
            );
        } catch (Throwable $e) {
            // Never log the exception message: database failures can carry the
            // connection string, while the class still tells us what failed.
            Log::warning('Member stats unavailable; rendering the profile empty state.', [
                'exception' => $e::class,
            ]);

            return MemberStats::unavailable($discordId);
        }

        if ($member === null) {
            return MemberStats::available(
                discordId: $discordId,
                joinedAt: null,
                tenureDays: null,
                rankKey: null,
                isCurrentMember: true,
                milestones: [],
            );
        }

        return MemberStats::available(
            discordId: $discordId,
            joinedAt: $this->timestampOrNull($member->joined_at ?? null),
            tenureDays: $this->intOrNull($member->tenure_days ?? null),
            rankKey: $this->stringOrNull($member->rank_key ?? null),
            isCurrentMember: (bool) ($member->is_current_member ?? true),
            milestones: array_values(array_filter(array_map(
                fn (object $row): ?Milestone => $this->milestone($row),
                $milestoneRows,
            ))),
        );
    }

    private function milestone(object $row): ?Milestone
    {
        $occurredAt = $this->timestampOrNull($row->occurred_at ?? null);

        if ($occurredAt === null) {
            return null;
        }

        return new Milestone(
            type: (string) ($row->milestone ?? ''),
            occurredAt: $occurredAt,
            detail: $this->stringOrNull($row->detail ?? null),
        );
    }

    private function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
