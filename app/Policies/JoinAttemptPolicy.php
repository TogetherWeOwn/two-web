<?php

namespace App\Policies;

use App\Models\JoinAttempt;
use App\Models\User;

/**
 * Read-only viewer for the join-attempt funnel audit trail (TOG-8401).
 *
 * Only `viewAny`/`view` are moderator-true; create, update and delete are
 * denied for everyone. The rows are a write-once audit trail produced by
 * JoinController — a moderator editing or deleting one would rewrite the
 * funnel history the JoinFunnelStats widget counts. Filament's base pages
 * add no header actions by default, so with no create/edit pages registered
 * the only writes left are policy-gated record actions, and there are none.
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
