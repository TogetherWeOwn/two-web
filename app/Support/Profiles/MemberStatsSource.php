<?php

namespace App\Support\Profiles;

interface MemberStatsSource
{
    public function forMember(string $discordId): MemberStats;
}
