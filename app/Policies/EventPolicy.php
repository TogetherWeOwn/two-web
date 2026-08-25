<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

/**
 * Moderators manage events; everyone else reads them.
 *
 * `is_moderator` is recomputed from the member's Discord roles at every login (see
 * DiscordLoginController), so removing somebody's moderator role in Discord removes
 * this here too, at their next sign-in. There is no way to grant it from inside the
 * website, which is why these methods ask about nothing else.
 *
 * Note what is *not* here: the host who created an event gets no extra rights. They
 * had to be a moderator to create it, and if they have since stopped being one then
 * they have stopped being one.
 */
class EventPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Event $event): bool
    {
        if ($event->status !== EventStatus::Draft) {
            return true;
        }

        return $user?->is_moderator === true;
    }

    /** Whether the listing should include drafts, which are not announced yet. */
    public function viewDrafts(?User $user): bool
    {
        return $user?->is_moderator === true;
    }

    public function create(User $user): bool
    {
        return $user->is_moderator === true;
    }

    public function update(User $user, Event $event): bool
    {
        return $user->is_moderator === true;
    }

    public function publish(User $user, Event $event): bool
    {
        return $user->is_moderator === true;
    }

    public function cancel(User $user, Event $event): bool
    {
        return $user->is_moderator === true;
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->is_moderator === true;
    }
}
