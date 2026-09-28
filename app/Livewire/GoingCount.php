<?php

namespace App\Livewire;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "N of M going" badge beside the RSVP control.
 *
 * The badge lives outside RsvpButton (in the event card and on the shareable
 * page), so a successful RSVP/withdraw left it showing the pre-click number
 * until a full reload (TOG-7966). RsvpButton now broadcasts
 * `going-count-updated` after every successful write, and this component
 * re-reads the aggregate for exactly that event.
 *
 * Scalar props only, never the model: the calendar mounts one of these per
 * card, and a locked model per card would rehydrate per card per request.
 * The initial count comes from the page's own `going_count` aggregate, so
 * mounting costs no query; a refresh is one count query on the answered card
 * only — every other card skips before touching the database.
 *
 * `role="status"`: the count changes without a reload, so the change has to
 * be announced politely. The visually-hidden prefix names what just happened
 * ("You're going. 4 of 20 going."), because a bare number gives a screen
 * reader user no reason for the change.
 */
class GoingCount extends Component
{
    #[Locked]
    public int $eventId;

    #[Locked]
    public string $eventKey;

    public int $going;

    public ?int $capacity;

    /**
     * Whether to also render the "N of M spots left" / "Full" signal beside
     * the count. Off by default: the calendar card only needs the count, and
     * the extra signal is specific to the shareable event page (TOG-6924).
     */
    public bool $showSpotsLeft = false;

    /**
     * What the last write did, from the dispatch that triggered the refresh:
     * `going`, `waitlisted`, `none`, `other`, or null before any write.
     */
    public ?string $announcement = null;

    public function mount(Event $event): void
    {
        $this->eventId = $event->getKey();
        $this->eventKey = $event->event_key;
        $this->going = $event->going_count ?? $event->goingCount();
        $this->capacity = $event->capacity;
    }

    #[On('going-count-updated')]
    public function refreshCount(string $eventKey, string $viewerState = 'other'): void
    {
        if ($eventKey !== $this->eventKey) {
            // Somebody answered a different event on the same page. Not our
            // card, not our query, not our re-render.
            $this->skipRender();

            return;
        }

        // The aggregate, re-read — never the page-load value.
        $this->going = Rsvp::query()
            ->where('event_id', $this->eventId)
            ->where('status', RsvpStatus::Going)
            ->count();

        $this->announcement = $viewerState;
    }

    public function render(): View
    {
        return view('livewire.going-count', [
            'announcementText' => match ($this->announcement) {
                'going' => "You're going.",
                'waitlisted' => "You're on the waitlist.",
                'none' => 'RSVP removed.',
                default => '',
            },
        ]);
    }
}
