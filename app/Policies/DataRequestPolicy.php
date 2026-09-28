<?php

namespace App\Policies;

use App\Models\DataRequest;
use App\Models\User;

/**
 * Same shape as FeaturedContentPolicy and for the same reason: `is_moderator`
 * is the one permission the site has, recomputed from Discord roles at every
 * login. Data requests are created by the member's own controller — never
 * through this policy — and decided only by moderators in the panel. Nobody
 * edits or deletes a row: the decision is the audit trail.
 */
class DataRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function view(User $user, DataRequest $dataRequest): bool
    {
        return $user->is_moderator === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DataRequest $dataRequest): bool
    {
        return $user->is_moderator === true;
    }

    public function delete(User $user, DataRequest $dataRequest): bool
    {
        return false;
    }
}
