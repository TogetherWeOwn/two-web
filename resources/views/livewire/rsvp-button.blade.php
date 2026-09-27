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
             saying so is shorter than explaining why the button is grey. --}}
        <a href="{{ route('login') }}"
           class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                  bg-transparent text-ink border border-line-strong
                  hover:bg-raised hover:border-ink-muted active:bg-surface
                  transition-colors duration-fast ease-out-quick">
            Log in with Discord
        </a>
    @endguest

    @auth
        @if (! $open)
            {{-- Cancelled or over. Says which, in words. --}}
            <p class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                      bg-raised text-ink-muted border border-line self-start"
               data-testid="rsvp-closed">
                {{ $this->event->status === \App\Enums\EventStatus::Cancelled ? 'Cancelled' : ($this->event->status === \App\Enums\EventStatus::Draft ? 'Not published yet' : 'This one has been and gone') }}
            </p>

        @elseif ($full || $atCapacity)
            {{-- Colour is not carrying this: there is an icon and there are words,
                 and the cap is named so the number is not a mystery. --}}
            <p class="flex items-start gap-1.5 text-sm text-alert" data-testid="event-full">
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

        @elseif ($going)
            <p class="flex items-center gap-1.5 text-sm text-ink" role="status" data-testid="rsvp-confirmed">
                {{-- The check, so the confirmation is not colour alone (COPY.md). --}}
                <svg class="size-4 shrink-0 text-online" viewBox="0 0 16 16" fill="currentColor"
                     aria-hidden="true" data-testid="rsvp-check">
                    <path d="M6.2 11.8 2.6 8.2l1.1-1.1 2.5 2.5 6.1-6.1 1.1 1.1-7.2 7.2Z"/>
                </svg>
                <span class="font-medium">You're in</span>
            </p>

            <button type="button"
                    wire:click="withdraw"
                    wire:loading.attr="disabled"
                    wire:target="withdraw"
                    data-testid="rsvp-withdraw"
                    class="inline-flex items-center justify-center gap-2 min-h-11 px-3 rounded-md
                           text-ink-muted hover:text-ink hover:bg-raised
                           transition-colors duration-fast ease-out-quick self-start">
                Can't make it
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
                <svg class="size-4 shrink-0 animate-spin" viewBox="0 0 16 16" fill="none"
                     aria-hidden="true" wire:loading wire:target="rsvp">
                    <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-opacity="0.3" stroke-width="2"/>
                    <path d="M14 8a6 6 0 0 0-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span wire:loading.remove wire:target="rsvp">I'm in</span>
                <span wire:loading wire:target="rsvp" aria-busy="true">Saving…</span>
            </button>
        @endif

        @if ($syncing && $going)
            {{-- The honest in-between. Saved here, not in Discord yet; the reconcile
                 pass closes this within ten minutes and nobody needs to do anything. --}}
            <p class="text-xs text-ink-muted" role="status" data-testid="rsvp-syncing">
                Saved. Syncing to Discord.
            </p>
        @elseif ($going)
            <p class="text-xs text-ink-muted" role="status" data-testid="rsvp-synced">
                Synced to Discord.
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
</div>
