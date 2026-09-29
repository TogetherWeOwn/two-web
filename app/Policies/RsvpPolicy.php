<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;

/**
 * A member answers for themselves and for nobody else.
 *
 * `create` deliberately takes the member being answered *for* as a third argument
 * rather than assuming it is the authenticated user. If the subject were implicit,
 * the rule would be enforced by whichever controller remembered to pass
 * `$request->user()` — which is not a rule, it is a habit. Called as:
 *
 *     Gate::allows('create', [Rsvp::class, $event, $subject])
 *
 * Moderating events is not the same permission as answering for a member, so a
 * moderator gets nothing extra here. Nobody speaks for somebody else's Friday
 * night.
 */
class RsvpPolicy
{
    public function create(User $user, Event $event, User $subject): bool
    {
        if (! $user->is($subject)) {
            return false;
        }

        // A draft is not visible and a cancelled event is not happening. Neither is
        // something to say yes to. The clock counts too: reconcile flips finished
        // rows to Past every ~10 min, so a recently finished event is still
        // Published — and the page already hides its RSVP button (TOG-7273). And
        // a moderator pause (TOG-8725) closes the gate while leaving the event
        // visible: the service repeats this check on the locked row (409) for
        // callers that reach it past here.
        return $event->status === EventStatus::Published && ! $event->hasEnded() && $event->isRsvpOpen();
    }

    public function update(User $user, Rsvp $rsvp): bool
    {
        return $user->getKey() === $rsvp->user_id;
    }

    public function delete(User $user, Rsvp $rsvp): bool
    {
        return $user->getKey() === $rsvp->user_id;
    }
}
