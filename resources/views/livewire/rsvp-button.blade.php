{{--
    The RSVP control. Tokens only — no hex, no arbitrary values (two.css rules 1
    and 2). Every state below is one the Designer listed in COMPONENTS.md.

    Note the ordering: full and closed are checked before the button is offered,
    so a control that cannot succeed is never rendered. An honest page does not
    hand somebody a button and then refuse them.
--}}
<div class="flex flex-col gap-2">
    @guest
        {{-- Not a disabled RSVP button. A guest's next action is to log in, and
             saying so is shorter than explaining why the button is grey.
             `?next=` returns them to this page after Discord (TOG-9254);
             `$returnTo` is the page path captured at render, null for a bare
             link. --}}
        <a href="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}"
           class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                  bg-transparent text-ink border border-line-strong
                  hover:bg-raised hover:border-ink-muted active:bg-surface
                  transition-colors duration-fast ease-out-quick">
            Log in with Discord
        </a>
    @endguest

    @auth
        @if (! $open)
            {{-- Cancelled or over. Says which, in words. role="status": a
                 cancellation that lands while the member is looking re-renders
                 here, and that change has to be announced (TOG-7332). --}}
            <p class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                      bg-raised text-ink-muted border border-line self-start"
               role="status" data-testid="rsvp-closed">
                {{ $this->event->status === \App\Enums\EventStatus::Cancelled ? 'Cancelled' : ($this->event->status === \App\Enums\EventStatus::Draft ? 'Not published yet' : 'This one has been and gone') }}
            </p>

        @elseif ($paused && ! $going && ! $waitlisted)
            {{-- A moderator pause (TOG-8725): still published, still visible,
                 taking no new answers. Only members with no stake see this —
                 a holder keeps their confirmation and withdraw below, someone
                 in line keeps their place and the way out of it. role="status":
                 a pause landing while the member watches re-renders here, and
                 that change has to be announced (TOG-7332). --}}
            <p class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                      bg-raised text-ink-muted border border-line self-start"
               role="status" data-testid="rsvp-paused">
                RSVPs are paused for this event — check back soon.
            </p>

        @elseif ($full || $atCapacity)
            {{-- Colour is not carrying this: there is an icon and there are words,
                 and the cap is named so the number is not a mystery.
                 role="status": the race loser lands here after clicking, so the
                 swap has to be announced politely, not as an alert (TOG-7332).
                 A refusal with nowhere to go is a dead end, so the line is
                 offered here: joining it is a waitlisted answer, not a seat, and
                 the locked write accepts it on a full event. --}}
            <p class="flex items-start gap-1.5 text-sm text-alert" role="status" data-testid="event-full">
                <svg class="size-4 shrink-0 mt-0.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                </svg>
                <span>
                    <span class="font-medium text-ink">This one's full.</span>
                    @if ($this->event->capacity !== null)
                        Cap is {{ $this->event->capacity }}.
                    @endif
                </span>
            </p>

            <button type="button"
                    wire:click="rsvp('{{ \App\Enums\RsvpStatus::Waitlisted->value }}')"
                    wire:loading.attr="disabled"
                    wire:target="rsvp"
                    data-testid="waitlist-join"
                    class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                           bg-transparent text-ink border border-line-strong
                           hover:bg-raised hover:border-ink-muted active:bg-surface
                           transition-colors duration-fast ease-out-quick self-start">
                <span wire:loading.remove wire:target="rsvp">Join the waitlist</span>
                {{-- Hidden up front like the main CTA below (TOG-6351):
                     Livewire only toggles loading elements mid-request. --}}
                <span wire:loading wire:target="rsvp" aria-busy="true" style="display: none">Saving…</span>
            </button>

        @elseif ($waitlisted)
            @if ($claimLost)
                {{-- TOG-8820: the member clicked "a seat opened up" and the seat
                     went to somebody else first. Their place never moved, so the
                     line view stays — with an honest note above it, not the full
                     refusal. role="status": this lands after a click, so the swap
                     announces politely (TOG-7332). --}}
                <p class="flex items-start gap-1.5 text-sm text-ink" role="status" data-testid="waitlist-claim-lost">
                    <span>
                        <span class="font-medium">Someone just took that seat.</span>
                        You're still in line — your place below hasn't moved.
                    </span>
                </p>
            @endif

            {{-- tabindex="-1": same swap as the confirmation — joining replaces
                 the button with this, so keyboard focus moves here (TOG-6956).
                 The place is named in words and digits, never colour alone. --}}
            <p class="flex items-center gap-1.5 text-sm text-ink" role="status" tabindex="-1" data-testid="waitlist-position">
                <span class="font-medium">
                    @if ($waitlistPosition !== null)
                        You're on the waitlist — #{{ $waitlistPosition }} in line
                    @else
                        You're on the waitlist
                    @endif
                </span>
            </p>

            @if ($seatOpenForWaitlist)
                {{-- A seat reads free while the member is still in line — only
                     possible mid-flight before their promotion renders, or when
                     a promotion never fired. A withdraw (or a raised cap) deals
                     freed seats to the head of the line in the same locked
                     write (TOG-8394); this control takes the seat through the
                     same locked write for whatever gap remains. --}}
                <button type="button"
                        wire:click="rsvp('{{ \App\Enums\RsvpStatus::Going->value }}')"
                        wire:loading.attr="disabled"
                        wire:target="rsvp"
                        data-testid="waitlist-claim"
                        class="inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                               bg-brand text-on-brand font-semibold
                               hover:bg-brand-hover active:bg-brand-active
                               disabled:opacity-100
                               transition-colors duration-fast ease-out-quick self-start">
                    <span wire:loading.remove wire:target="rsvp">A seat opened up — I'm in</span>
                    {{-- Hidden up front like the main CTA below (TOG-6351). --}}
                    <span wire:loading wire:target="rsvp" aria-busy="true" style="display: none">Saving…</span>
                </button>
            @endif

            <button type="button"
                    wire:click="withdraw"
                    wire:loading.attr="disabled"
                    wire:target="withdraw"
                    data-testid="waitlist-leave"
                    class="inline-flex items-center justify-center gap-2 min-h-11 px-3 rounded-md
                           text-ink-muted hover:text-ink hover:bg-raised
                           transition-colors duration-fast ease-out-quick self-start">
                Leave the waitlist
            </button>

        @elseif ($going)
            {{-- tabindex="-1": not in the tab order, but focusable so a successful
                 RSVP can move keyboard focus here after the re-render replaces
                 the button (TOG-6956). Focusing the role="status" node also
                 announces the confirmation to screen readers. --}}
            <p class="flex items-center gap-1.5 text-sm text-ink" role="status" tabindex="-1" data-testid="rsvp-confirmed">
                {{-- The check, so the confirmation is not colour alone (COPY.md). --}}
                <svg class="size-4 shrink-0 text-online" viewBox="0 0 16 16" fill="currentColor"
                     aria-hidden="true" data-testid="rsvp-check">
                    <path d="M6.2 11.8 2.6 8.2l1.1-1.1 2.5 2.5 6.1-6.1 1.1 1.1-7.2 7.2Z"/>
                </svg>
                <span class="font-medium">You're in</span>
            </p>

            {{-- Same loading contract as the "I'm in" button below: the box is
                 reserved up front (CLS budget 0.1), the control disables while
                 the answer is in flight, and the spinner + copy swap in with
                 aria-busy so the wait is announced (TOG-5416). --}}
            <button type="button"
                    wire:click="withdraw"
                    wire:loading.attr="disabled"
                    wire:target="withdraw"
                    aria-busy="false"
                    data-testid="rsvp-withdraw"
                    class="inline-flex items-center justify-center gap-2 min-h-11 px-3 rounded-md
                           text-ink-muted hover:text-ink hover:bg-raised
                           transition-colors duration-fast ease-out-quick self-start">
                {{-- Hidden up front like the RSVP control above (TOG-6351):
                     Livewire only toggles loading elements mid-request. --}}
                <span wire:loading.remove wire:target="withdraw">Can't make it</span>
                <span wire:loading wire:target="withdraw" aria-busy="true" style="display: none">Removing…</span>
            </button>

        @else
            {{-- The box is reserved in the default state so the spinner cannot change
                 it — CLS budget is 0.1 and a growing button is the classic way to
                 blow it (COMPONENTS.md, global Loading rule). --}}
            <button type="button"
                    wire:click="rsvp('{{ \App\Enums\RsvpStatus::Going->value }}')"
                    wire:loading.attr="disabled"
                    wire:target="rsvp"
                    aria-busy="false"
                    data-testid="rsvp-going"
                    class="inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                           bg-brand text-on-brand font-semibold
                           hover:bg-brand-hover active:bg-brand-active
                           disabled:opacity-100
                           transition-colors duration-fast ease-out-quick self-start">
                <span class="size-4 shrink-0" aria-hidden="true" wire:loading.remove wire:target="rsvp"></span>
                {{-- Hidden up front: Livewire only toggles loading elements during a
                     request and never at init, so without this the spinner and the
                     loading copy render beside the default copy (TOG-6351). The
                     directive sets style.display itself mid-request, overriding this. --}}
                <svg class="size-4 shrink-0 animate-spin" viewBox="0 0 16 16" fill="none"
                     aria-hidden="true" wire:loading wire:target="rsvp" style="display: none">
                    <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-opacity="0.3" stroke-width="2"/>
                    <path d="M14 8a6 6 0 0 0-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span wire:loading.remove wire:target="rsvp">I'm in</span>
                <span wire:loading wire:target="rsvp" aria-busy="true" style="display: none">Saving…</span>
            </button>
        @endif

        @if ($syncing && $going)
            {{-- The honest in-between. Saved here, not in Discord yet; the reconcile
                 pass closes this within ten minutes and nobody needs to do anything. --}}
            <p class="text-xs text-ink-muted" role="status" data-testid="rsvp-syncing">
                Saved. Syncing to Discord.
            </p>
        @elseif ($syncFailed && $going)
            {{-- TOG-6990: the third state. The bot refused the mirror terminally,
                 so this will not retry until somebody changes something — but
                 the answer is saved and counts. role="status", not alert: there
                 is nothing to act on and nobody did anything wrong. --}}
            <p class="text-xs text-ink-muted" role="status" data-testid="rsvp-sync-failed">
                Saved. Discord sync didn't go through — your spot is still held.
            </p>
        @elseif ($going)
            <p class="text-xs text-ink-muted" role="status" data-testid="rsvp-synced">
                Synced to Discord.
            </p>
        @endif

        @if ($rateLimitedMessage !== null)
            {{-- TOG-7976: the throttle wait. role="status", not alert: a throttle is
                 temporary, not a failure that interrupts (CM spec in TOG-7928
                 `copy` doc). Beside the control with the button enabled, like
                 rsvp-failed below — never disabling or replacing the control. --}}
            <p class="flex items-start gap-1.5 text-sm text-ink-muted" role="status" data-testid="rsvp-rate-limited">
                <svg class="size-4 shrink-0 mt-0.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13Zm.75 3.75a.75.75 0 0 0-1.5 0v3.5a.75.75 0 0 0 1.5 0V5.25ZM8 11.5a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                </svg>
                <span>{{ $rateLimitedMessage }}</span>
            </p>
        @endif

        @if ($failed)
            {{-- role="alert": this interrupted something they were doing, unlike the
                 confirmation above (ACCESSIBILITY.md 4.1.3). --}}
            <p class="flex items-start gap-1.5 text-sm text-alert" role="alert" data-testid="rsvp-failed">
                <svg class="size-4 shrink-0 mt-0.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                </svg>
                <span>
                    <span class="font-medium text-ink">That RSVP didn't save.</span>
                    Try once more.
                </span>
            </p>
        @endif

    @endauth

    {{-- Deliberately outside @auth. After the session dies the round trip
         re-renders as a guest, which would hide a banner placed inside the
         block above — and the guest block it shows instead carries the same
         login link, so the message survives either way (TOG-8135). --}}
    @if ($sessionExpired ?? false)
        <p class="flex items-start gap-1.5 text-sm text-alert" role="alert" data-testid="rsvp-session-expired">
            <svg class="size-4 shrink-0 mt-0.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
            </svg>
            <span>
                <span class="font-medium text-ink">Your session expired.</span>
                <a href="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}" class="font-semibold underline underline-offset-4 hover:text-ink">Log in with Discord</a>
                and try again.
            </span>
        </p>
    @endif

    @script
        {{-- TOG-6956: after a successful RSVP/withdraw the re-render swaps the
             focused control for its replacement, dropping focus to <body>.
             The component dispatches `rsvp-state-changed` to itself only on a
             successful write, which fires after the morph, so the new state is
             already in the DOM: focus the confirmation, falling back to the
             restored "I'm in" button after a withdraw. `$wire.on` runs once
             per component lifecycle, never on re-render, so this cannot stack. --}}
        <script>
            $wire.on('rsvp-state-changed', () => {
                const root = $wire.el;
                const confirmed = root.querySelector('[data-testid="rsvp-confirmed"]');
                if (confirmed) {
                    confirmed.focus({ preventScroll: true });
                    return;
                }

                // Joining the line swaps the button for the place in line, the
                // same focus loss as a successful RSVP (TOG-6956).
                const position = root.querySelector('[data-testid="waitlist-position"]');
                if (position) {
                    position.focus({ preventScroll: true });
                    return;
                }

                const going = root.querySelector('[data-testid="rsvp-going"], [data-testid="waitlist-join"]');
                if (going) {
                    going.focus({ preventScroll: true });
                }
            });

            // TOG-9354: the round trip above never reaches the component when
            // the session died underneath the page (SESSION_LIFETIME). The
            // POST dies first in ValidateCsrfToken — the page holds a stale
            // data-csrf token against a fresh session — answering 419, and
            // Livewire's handlePageExpiry answers that with a native
            // confirm(), so the branded session-expired banner below stays
            // unreachable. Intercept the 419 before Livewire's default:
            // prevent the confirm and reload into the guest render, which
            // carries the same login link with the ?next= return. 419-only
            // on purpose: any other failure still gets Livewire's failure
            // modal. No loop: the reloaded guest page issues no POST.
            $wire.$hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status !== 419) {
                        return;
                    }

                    preventDefault();
                    window.location.reload();
                });
            });
        </script>
    @endscript
</div>
