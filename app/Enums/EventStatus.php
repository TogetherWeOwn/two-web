<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    /**
     * It happened, and it is over.
     *
     * A separate state from Cancelled because they are different things to a member
     * — one ran and one did not — and different things to the write-back: Discord
     * removes its own past events, so a past event must stop being upserted rather
     * than be chased forever by the reconcile pass.
     *
     * Set by `events:reconcile`, never by a host: an event ends by the clock.
     */
    case Past = 'past';

    /**
     * Whether Discord has been told about an event in this state, and so whether a
     * write-back has anything to update.
     *
     * Draft was never announced. Past is Discord's to forget. Cancelled *has* been
     * mirrored — the Discord event needs updating to say so, which is why it is here
     * and not with the two that are skipped.
     */
    public function isMirroredInDiscord(): bool
    {
        return match ($this) {
            self::Published, self::Cancelled => true,
            self::Draft, self::Past => false,
        };
    }
}
