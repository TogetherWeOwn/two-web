<?php

namespace App\Policies;

use App\Models\AgentEventGrant;
use App\Models\User;

/**
 * The admitted machine-actor grants for the agent event ingress (Gate 2).
 *
 * Reads are moderator-only: a grant row names the admitted caller, the
 * company and the guild, and sits next to the verifier hash that its opaque
 * credential alone resolves to. That is admin-adjacent inventory, not member
 * data.
 *
 * Writes are denied to everybody, moderators included. A grant is admitted by
 * the CISO outside the website — there is no flow in which a signed-in person
 * mints, edits or deletes one, and the credential itself is never stored, so
 * there is no edit form this policy could truthfully enable. The machine
 * ingress authenticates by credential possession (see AgentEventService), not
 * through this policy, which only ever answers for human users.
 */
class AgentEventGrantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function view(User $user, AgentEventGrant $grant): bool
    {
        return $user->is_moderator === true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AgentEventGrant $grant): bool
    {
        return false;
    }

    public function delete(User $user, AgentEventGrant $grant): bool
    {
        return false;
    }
}
