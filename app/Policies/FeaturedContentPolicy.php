<?php

namespace App\Policies;

use App\Models\FeaturedContent;
use App\Models\User;

/**
 * Same shape as EventPolicy and for the same reason: `is_moderator` is the one
 * permission the site has, recomputed from Discord roles at every login. The
 * public landing page does not come through here — it reads the
 * `currentlyVisible` scope with no user at all. This policy exists for the
 * admin panel, where every verb is a moderator verb.
 */
class FeaturedContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function view(User $user, FeaturedContent $featuredContent): bool
    {
        return $user->is_moderator === true;
    }

    public function create(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function update(User $user, FeaturedContent $featuredContent): bool
    {
        return $user->is_moderator === true;
    }

    public function delete(User $user, FeaturedContent $featuredContent): bool
    {
        return $user->is_moderator === true;
    }
}
