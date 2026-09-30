<?php

namespace App\Policies;

use App\Models\JoinAttempt;
use App\Models\User;

/**
 * Same shape as FeaturedContentPolicy and for the same reason: `is_moderator`
 * is the one permission the site has, recomputed from Discord roles at every
 * login. Join-attempt rows are written by JoinController itself — never
 * through this policy — and read by the admin JoinFunnelStats widget and the
 * read-only JoinAttemptResource viewer, so every human verb here is a
 * moderator verb and every write verb is denied to everybody, moderators
 * included. No flow exists in which a person creates, edits or deletes a
 * row.
 */
class JoinAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function view(User $user, JoinAttempt $joinAttempt): bool
    {
        return $user->is_moderator === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JoinAttempt $joinAttempt): bool
    {
        return false;
    }

    public function delete(User $user, JoinAttempt $joinAttempt): bool
    {
        return false;
    }
}
