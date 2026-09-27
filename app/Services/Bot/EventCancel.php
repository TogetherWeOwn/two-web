<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;

/**
 * One `event.cancel` call, validated at construction.
 *
 * Cancelling is a distinct action from upserting, not a flag on it: the bot
 * answers `event.upsert` by creating or updating a Discord scheduled event,
 * and only `event.cancel` moves it to CANCELED (Discord `status: 4`, never a
 * delete). Sending a cancelled event through `event.upsert` re-receives what
 * looks like a live event, which is exactly the bug this type exists to close.
 *
 * Two things the contract pins down and this type carries:
 *
 * - The bot keys on its `event_key -> discord_event_id` map, never on a
 *   Discord id we supply — there is no field for one, so a caller cannot
 *   address an arbitrary Discord event. Unknown keys come back
 *   `action_not_allowed` without a Discord request.
 * - The bot retains the mapping on success, so a delayed upsert cannot
 *   resurrect the cancelled event. Scheduling a replacement takes a new
 *   `event_key`, which is a product decision the type cannot make and so does
 *   not try to.
 */
final readonly class EventCancel
{
    public function __construct(
        public string $eventKey,
    ) {
        $this->validate();
    }

    /**
     * The wire payload, in the order it will be serialised and signed.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'action' => 'event.cancel',
            'event_key' => $this->eventKey,
        ];
    }

    private function validate(): void
    {
        if (trim($this->eventKey) === '') {
            throw new InvalidActionRequestException('An event.cancel needs an event_key: it is how the bot finds the event to cancel.');
        }
    }
}
