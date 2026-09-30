<?php

namespace App\Support\Profiles;

use App\Livewire\MemberProfile;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single profile writer (TOG-6966).
 *
 * TOG-8440 deleted the PATCH writer, so {@see MemberProfile::save()} is the
 * only caller. First-time creation used to be a bare
 * `$member->profile()->updateOrCreate([])`: SELECT-then-INSERT with no lock.
 * Two concurrent first saves both missed, both INSERTed, and the loser died on
 * `profiles_user_id_unique` (SQLSTATE 23505). Laravel's `createOrFirst` rescue
 * usually healed that after the fact, but the window was real and the outcome
 * depended on exception timing rather than on the database deciding an order.
 *
 * The write now runs inside a transaction that takes `lockForUpdate()` on the
 * parent users row first. The parent always exists; the child may not — so the
 * parent is the only row both competitors are guaranteed to meet on. The loser
 * waits there until the winner commits, its SELECT then sees the committed
 * row, and the write becomes an UPDATE. Exactly one profile row, no 23505.
 */
final class SaveMemberProfile
{
    /**
     * @param  array{bio: ?string, games: list<string>, timezone: ?string}  $attributes
     */
    public function save(User $member, array $attributes): Profile
    {
        return DB::transaction(function () use ($member, $attributes): Profile {
            User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            return $member->profile()->updateOrCreate([], $attributes);
        });
    }
}
