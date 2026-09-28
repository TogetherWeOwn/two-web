<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;

/**
 * One `event.read` call, validated at construction.
 *
 * The minimal mapped-event verifier the Gate 2 scope asks for: given the owned
 * `event_key`, the bot answers with the mirror it mapped — id, name, start,
 * location, lifecycle — or `action_not_allowed` for an unknown key. There is
 * no field for a Discord id and no room for a predicate, so a caller cannot
 * address an arbitrary Discord event or ask an arbitrary question about the
 * guild. No listing, no attendees, no member data.
 *
 * Typed now against the bot slice's contract so the web side fails closed
 * (`verification_unavailable`) rather than inventing a second read path while
 * the bot slice is still in progress on TOG-5510.
 */
final readonly class EventRead
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
            'action' => 'event.read',
            'event_key' => $this->eventKey,
        ];
    }

    private function validate(): void
    {
        if (trim($this->eventKey) === '') {
            throw new InvalidActionRequestException('An event.read needs an event_key: it is how the bot finds the mirror to report on.');
        }
    }
}
