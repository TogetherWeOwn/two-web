{{--
    data-login-url: the way back in with the ?next= return (TOG-9254). The
    @script 419 hook below navigates here instead of reloading — a reload on
    this auth-walled page would bounce through the middleware's intended URL,
    while this keeps the explicit return the callback prefers. Rendered always,
    not only with the expired banner, because the 419 path never reaches the
    server render that would show the banner.
--}}
<div class="min-h-full bg-canvas text-ink"
     data-profile-id="{{ $member->getKey() }}"
     data-draft-owner="{{ $isOwner ? '1' : '0' }}"
     data-login-url="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}">
    <div hidden role="alert" tabindex="-1" data-testid="profile-draft-unavailable"
         class="mx-auto max-w-6xl rounded-lg border border-line bg-surface p-5 text-sm text-ink">
        Your session expired. Copy your changes before logging in; this browser could not keep a draft.
        <a href="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}"
           class="font-semibold underline underline-offset-4">Log in after copying your changes</a>
    </div>
    <div class="mx-auto max-w-6xl px-4 py-8 md:px-6 md:py-12 lg:px-8">
        <div class="flex flex-col gap-8">
            <header class="rounded-lg border border-line bg-surface p-5 md:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                    <div class="shrink-0">
                        @if ($member->avatar)
                            {{-- Eager on purpose: this is the page header, above the fold and a
                                 likely LCP candidate, so `loading="lazy"` would delay it.
                                 `width`/`height` reserve the 96px box (no CLS); the 2x
                                 srcset serves retina via the Discord CDN `size` param
                                 (powers of two only, so 256 covers the 192px 2x need). --}}
                            @php($avatarSrcsetSeparator = str_contains((string) $member->avatar, '?') ? '&' : '?')
                            <img src="{{ $member->avatar }}"
                                 srcset="{{ $member->avatar }}{{ $avatarSrcsetSeparator }}size=256 2x"
                                 sizes="96px"
                                 alt=""
                                 width="96"
                                 height="96"
                                 decoding="async"
                                 class="size-24 rounded-full bg-raised object-cover"
                                 data-testid="profile-avatar-img"
                                 onerror="this.onerror=null;this.hidden=true;this.nextElementSibling.hidden=false">
                        @endif

                        <div @if ($member->avatar) hidden @endif
                             class="flex size-24 items-center justify-center rounded-full border border-line bg-raised text-3xl text-ink-muted u-display"
                             aria-hidden="true"
                             data-testid="profile-avatar-fallback">
                            {{ str($member->display_name ?? $member->username)->substr(0, 2)->upper() }}
                        </div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink-muted">{{ $isOwner ? 'Your profile' : 'Member profile' }}</p>
                        <h1 class="mt-1 break-words text-3xl text-ink u-display md:text-4xl">
                            {{ $member->display_name ?? $member->username }}
                        </h1>
                        <p class="mt-2 break-all text-base text-ink-muted">{{ '@'.$member->username }}</p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">
                                From Discord
                            </span>

                            @if ($statsAvailable && $rankKey)
                                <span class="inline-flex items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">
                                    {{ str($rankKey)->headline() }}
                                </span>
                            @endif

                            @if ($statsJoinedAt ?? $member->discord_joined_at)
                                <span class="inline-flex items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">
                                    Joined {{ $statsJoinedAt ? \Illuminate\Support\Carbon::parse($statsJoinedAt)->format('F Y') : $member->discord_joined_at->format('F Y') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($isOwner && ! $editing)
                        <button type="button"
                                wire:click="edit"
                                data-testid="profile-edit"
                                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised active:bg-surface">
                            Edit profile
                        </button>
                    @endif
                </div>
            </header>

            @if ($saved)
                {{-- tabindex="-1": not in the tab order, but focusable so a
                     successful save can move keyboard focus here after the
                     re-render replaces the form (TOG-6957). Focusing the
                     role="status" node also announces it to screen readers. --}}
                <p class="flex items-center gap-2 rounded-lg border border-line bg-online-quiet p-4 text-sm text-ink"
                   role="status"
                   tabindex="-1"
                   data-testid="profile-saved">
                    <svg class="size-4 shrink-0 text-online" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <path d="M6.2 11.8 2.6 8.2l1.1-1.1 2.5 2.5 6.1-6.1 1.1 1.1-7.2 7.2Z"/>
                    </svg>
                    Profile saved.
                </p>
            @endif

            @if ($editing)
                <section class="rounded-lg border border-line bg-surface p-5 md:p-8" aria-labelledby="edit-profile-heading">
                    <div class="max-w-2xl">
                        {{-- tabindex="-1": the open-form focus target (TOG-6957).
                             Not in the tab order — reached by the listener, not by Tab. --}}
                        <h2 id="edit-profile-heading" tabindex="-1" class="text-2xl font-semibold text-ink">Edit your profile</h2>
                        <p class="mt-2 text-base text-ink-muted">Your name, avatar, join date and rank come from Discord.</p>

                        @if ($errors->any() || $saveFailed)
                            {{-- tabindex="-1": the invalid-save focus target
                                 (TOG-6957). A failed save keeps the form open
                                 but drops focus to <body>; the listener moves
                                 it here so keyboard and screen-reader users
                                 land on the error summary. --}}
                            <div class="mt-5 flex items-start gap-3 rounded-lg border border-line bg-alert-quiet p-4"
                                 role="alert"
                                 tabindex="-1"
                                 data-testid="profile-edit-failed">
                                <svg class="mt-0.5 size-5 shrink-0 text-alert" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                    <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                                </svg>
                                <div>
                                    @if ($saveFailed)
                                        <p class="text-sm font-medium text-ink">That profile did not save.</p>
                                        <p class="mt-0.5 text-sm text-ink-muted">Your changes are still here. Try once more.</p>
                                    @else
                                        <p class="text-sm font-medium text-ink">Check the highlighted fields and try again.</p>
                                        <p class="mt-0.5 text-sm text-ink-muted">Your changes are still here.</p>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- TOG-8137: the save (or cancel) arrived with no
                             signed-in member behind it — the form was opened
                             authenticated and the session died underneath it
                             (SESSION_LIFETIME). The form stays open with the
                             member's input intact; only the write was refused.
                             Distinct from the $saveFailed alert on purpose:
                             the next action is to log in again, not to try
                             once more, so the message says that. Mirrors the
                             RSVP session-expired banner (TOG-8135). --}}
                        @if ($sessionExpired ?? false)
                            <div class="mt-5 flex items-start gap-3 rounded-lg border border-line bg-alert-quiet p-4"
                                 role="alert"
                                 tabindex="-1"
                                 data-testid="profile-session-expired">
                                <svg class="mt-0.5 size-5 shrink-0 text-alert" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                    <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-ink">Your session expired.</p>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        Your changes are still here.
                                        {{-- TOG-9355: the ?next= return puts them
                                             back on this page after Discord,
                                             next to their still-open draft —
                                             a bare link strands them on
                                             /profile instead. Mirrors the
                                             RSVP login link (TOG-9254). --}}
                                        <a href="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}" data-profile-login class="font-semibold text-ink underline underline-offset-4 hover:text-ink">Log in with Discord</a>
                                        and save again.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- TOG-9355: the 419-stashed draft made it back. The
                             session is alive again — they already logged back
                             in — so this names the kept draft, not the expiry.
                             role="status", not alert: nothing was interrupted
                             on this page load, the draft simply arrived. --}}
                        @if ($draftRestored ?? false)
                            <div class="mt-5 flex items-start gap-3 rounded-lg border border-line bg-online-quiet p-4"
                                 role="status"
                                 tabindex="-1"
                                 data-testid="profile-draft-restored">
                                <svg class="mt-0.5 size-5 shrink-0 text-online" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                    <path d="M6.2 11.8 2.6 8.2l1.1-1.1 2.5 2.5 6.1-6.1 1.1 1.1-7.2 7.2Z"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-ink">Your unsent changes are back.</p>
                                    <p class="mt-0.5 text-sm text-ink-muted">Review them and save when ready.</p>
                                </div>
                            </div>
                        @endif

                        <form wire:submit="save" class="mt-6 flex flex-col gap-5" data-testid="profile-edit-form">
                            {{--
                                Honeypot decoy (TOG-8715). Visually hidden and
                                removed from the tab order and the accessibility
                                tree, with no <label> on purpose: a screen reader
                                must never announce it, and the label audit in
                                FormLabelCoverageTest must never see it as a
                                labelled control. Bots filling every input fill
                                this; humans never do. autocomplete="off" keeps
                                password managers from touching it.
                            --}}
                            <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
                                {{-- id="website": not for a <label> (there is
                                     none on purpose — the audit below exempts
                                     this exact decoy) but so the static label
                                     scan sees an identifiable control rather
                                     than an id-less orphan. --}}
                                <input id="website"
                                       type="text"
                                       name="website"
                                       wire:model="website"
                                       tabindex="-1"
                                       autocomplete="off"
                                       data-testid="profile-website-trap">
                            </div>
                            <div>
                                <label for="bio" class="mb-1.5 block text-sm font-medium text-ink">Bio</label>
                                <textarea id="bio"
                                          name="bio"
                                          wire:model="bio"
                                          rows="5"
                                          maxlength="1000"
                                          placeholder="What do you play, and when are you usually around?"
                                          @error('bio') aria-invalid="true" aria-describedby="bio-error" @enderror
                                          class="w-full rounded-md border bg-canvas px-3 py-2.5 text-ink placeholder:text-ink-muted transition-colors duration-fast ease-out-quick hover:border-ink-muted @error('bio') border-alert @else border-line-strong @enderror"></textarea>
                                <p class="mt-1.5 text-sm text-ink-muted">A few sentences is plenty.</p>
                                @error('bio')
                                    @include('partials.field-error', ['id' => 'bio-error', 'message' => $message])
                                @enderror
                            </div>

                            <div>
                                <label for="games" class="mb-1.5 block text-sm font-medium text-ink">Games</label>
                                <textarea id="games"
                                          name="gamesText"
                                          wire:model="gamesText"
                                          rows="5"
                                          placeholder="One game per line"
                                          @error('gamesText') aria-invalid="true" aria-describedby="games-error" @enderror
                                          class="w-full rounded-md border bg-canvas px-3 py-2.5 text-ink placeholder:text-ink-muted transition-colors duration-fast ease-out-quick hover:border-ink-muted @error('gamesText') border-alert @else border-line-strong @enderror"></textarea>
                                <p class="mt-1.5 text-sm text-ink-muted">Up to 20. Duplicates are removed.</p>
                                @error('gamesText')
                                    @include('partials.field-error', ['id' => 'games-error', 'message' => $message])
                                @enderror
                            </div>

                            <div>
                                <label for="timezone" class="mb-1.5 block text-sm font-medium text-ink">Timezone</label>
                                <input id="timezone"
                                       type="text"
                                       name="timezone"
                                       wire:model="timezone"
                                       list="timezones"
                                       placeholder="Europe/London"
                                       @error('timezone') aria-invalid="true" aria-describedby="timezone-error" @enderror
                                       class="min-h-11 w-full rounded-md border bg-canvas px-3 text-ink placeholder:text-ink-muted transition-colors duration-fast ease-out-quick hover:border-ink-muted @error('timezone') border-alert @else border-line-strong @enderror">
                                <datalist id="timezones">
                                    @foreach (timezone_identifiers_list() as $timezoneOption)
                                        <option value="{{ $timezoneOption }}"></option>
                                    @endforeach
                                </datalist>
                                <p class="mt-1.5 text-sm text-ink-muted">Use an IANA timezone such as America/New_York.</p>
                                @error('timezone')
                                    @include('partials.field-error', ['id' => 'timezone-error', 'message' => $message])
                                @enderror
                            </div>

                            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                                <button type="button"
                                        wire:click="cancel"
                                        class="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                                    Cancel
                                </button>
                                <button type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="save"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-brand px-6 font-semibold text-on-brand transition-colors duration-fast ease-out-quick hover:bg-brand-hover active:bg-brand-active disabled:cursor-not-allowed disabled:border disabled:border-line disabled:bg-surface disabled:text-ink-disabled">
                                    <span class="size-4 shrink-0" aria-hidden="true" wire:loading.remove wire:target="save"></span>
                                    {{-- Hidden up front for the same reason as the RSVP control (TOG-6351). --}}
                                    <svg class="size-4 shrink-0 animate-spin" viewBox="0 0 16 16" fill="none" aria-hidden="true" wire:loading wire:target="save" style="display: none">
                                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-opacity="0.3" stroke-width="2"/>
                                        <path d="M14 8a6 6 0 0 0-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <span wire:loading.remove wire:target="save">Save</span>
                                    <span wire:loading wire:target="save" aria-busy="true" style="display: none">Saving…</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            @else
                @if ($isNewMember && $isOwner)
                    <section class="u-hatch rounded-lg border border-line p-6 text-center md:p-8"
                             aria-labelledby="new-member-heading"
                             data-testid="profile-new-member">
                        <h2 id="new-member-heading" class="text-lg font-semibold text-ink">Your profile has room to grow.</h2>
                        <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">Add a bio, a few games and your timezone so people know when to find you.</p>
                        <div class="mt-5">
                            <button type="button"
                                    wire:click="edit"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-brand px-6 font-semibold text-on-brand transition-colors duration-fast ease-out-quick hover:bg-brand-hover active:bg-brand-active">
                                Add profile details
                            </button>
                        </div>
                    </section>
                @endif

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="flex flex-col gap-6 lg:col-span-2">
                        <section class="rounded-lg border border-line bg-surface p-5 md:p-6" aria-labelledby="about-heading">
                            <h2 id="about-heading" class="text-2xl font-semibold text-ink">About</h2>
                            @if ($profile->bio)
                                <p class="mt-3 max-w-prose whitespace-pre-line text-base text-ink">{{ $profile->bio }}</p>
                            @elseif (! $isNewMember)
                                <p class="mt-3 text-base text-ink-muted">{{ $isOwner ? 'You have not added a bio yet.' : ($member->display_name ?? $member->username).' has not added a bio yet.' }}</p>
                            @else
                                <p class="mt-3 text-base text-ink-muted">New here. More soon.</p>
                            @endif
                        </section>

                        <section class="rounded-lg border border-line bg-surface p-5 md:p-6" aria-labelledby="activity-heading">
                            <h2 id="activity-heading" class="text-2xl font-semibold text-ink">Activity</h2>

                            @if (! $statsAvailable)
                                <div role="status"
                                     class="mt-4 flex items-start gap-3 rounded-lg border border-line bg-alert-quiet p-4"
                                     data-testid="profile-stats-unavailable">
                                    <svg class="mt-0.5 size-5 shrink-0 text-alert" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/>
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-ink">Activity is taking a breather.</p>
                                        <p class="mt-0.5 text-sm text-ink-muted">The rest of this profile is still here.</p>
                                    </div>
                                </div>
                            @elseif ($milestones === [])
                                <div class="u-hatch mt-4 rounded-lg border border-line p-6 text-center">
                                    @if ($isOwner)
                                        <h3 class="text-lg font-semibold text-ink">You haven't RSVP'd to anything yet.</h3>
                                        <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">When you do, it shows up here.</p>
                                        <div class="mt-5">
                                            <a href="{{ route('events.index') }}"
                                               class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised active:bg-surface">
                                                Browse events
                                            </a>
                                        </div>
                                    @else
                                        <h3 class="text-lg font-semibold text-ink">{{ $member->display_name ?? $member->username }} hasn't RSVP'd to an event yet.</h3>
                                    @endif
                                </div>
                            @else
                                <ol class="mt-4 divide-y divide-line">
                                    @foreach ($milestones as $milestone)
                                        <li class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                                            <span class="font-medium text-ink">
                                                {{ str($milestone['type'])->replace('_', ' ')->headline() }}
                                                @if ($milestone['detail'])
                                                    — {{ str($milestone['detail'])->headline() }}
                                                @endif
                                            </span>
                                            <time datetime="{{ $milestone['occurredAt'] }}" class="shrink-0 text-sm text-ink-muted u-numeric">
                                                {{ \Illuminate\Support\Carbon::parse($milestone['occurredAt'])->format('j M Y') }}
                                            </time>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </section>
                    </div>

                    <aside class="flex flex-col gap-6" aria-label="Profile details">
                        <section class="rounded-lg border border-line bg-surface p-5 md:p-6" aria-labelledby="games-heading">
                            <h2 id="games-heading" class="text-xl font-semibold text-ink">Games</h2>
                            @if ($games !== [])
                                <ul class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($games as $game)
                                        <li class="inline-flex items-center rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">{{ $game }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-3 text-sm text-ink-muted">{{ $isOwner ? 'Add the games you keep coming back to.' : 'No games listed yet.' }}</p>
                            @endif
                        </section>

                        <section class="rounded-lg border border-line bg-surface p-5 md:p-6" aria-labelledby="timezone-heading">
                            <h2 id="timezone-heading" class="text-xl font-semibold text-ink">Timezone</h2>
                            <p class="mt-3 text-sm {{ $profile->timezone ? 'text-ink' : 'text-ink-muted' }}">{{ $profile->timezone ?: ($isOwner ? 'Add yours so people know when you are around.' : 'Not listed yet.') }}</p>
                        </section>
                    </aside>
                </div>
            @endif

            @can('access-admin')
                <a href="/admin"
                   data-testid="admin-link"
                   class="u-link self-start text-sm">
                    Moderator admin
                </a>
            @endcan

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                    Sign out
                </button>
            </form>
        </div>
    </div>

    @script
        {{-- TOG-6957: every profile state change unmounts the focused control
             (Edit opens the form, save/cancel removes it), dropping keyboard
             focus to <body>. The component dispatches `profile-state-changed`
             to itself on edit, cancel and save — save dispatches BEFORE
             validation so a ValidationException cannot swallow it, and the
             session-expired early returns (TOG-8137) dispatch too so the
             banner can take focus — which fires after the morph, so the new
             state is already in the DOM. The listener picks its target from
             what rendered, in priority order: error alert (invalid save),
             session-expired banner (TOG-8137), saved confirmation (valid
             save), form heading (opened), Edit button (cancelled).
             `$wire.on` runs once per component lifecycle, never on re-render,
             so this cannot stack. --}}
        <script>
            $wire.on('profile-state-changed', () => {
                const root = $wire.el;

                const failed = root.querySelector('[data-testid="profile-edit-failed"]');
                if (failed) {
                    failed.focus({ preventScroll: true });
                    return;
                }

                // TOG-8137: the expired banner renders inside the still-open
                // form, so without this the listener would fall through to
                // the heading and skip the alert that interrupted them.
                const expired = root.querySelector('[data-testid="profile-session-expired"]');
                if (expired) {
                    expired.focus({ preventScroll: true });
                    return;
                }

                // TOG-9355: a 419-stashed draft just landed back in the open
                // form. Not an interruption, so it comes after the alerts —
                // but before the heading/saved fallthroughs, which would
                // strand focus away from the news that their text survived.
                const restored = root.querySelector('[data-testid="profile-draft-restored"]');
                if (restored) {
                    restored.focus({ preventScroll: true });
                    return;
                }

                const saved = root.querySelector('[data-testid="profile-saved"]');
                if (saved) {
                    saved.focus({ preventScroll: true });
                    return;
                }

                const heading = root.querySelector('#edit-profile-heading');
                if (heading && root.querySelector('[data-testid="profile-edit-form"]')) {
                    heading.focus({ preventScroll: true });
                    return;
                }

                const edit = root.querySelector('[data-testid="profile-edit"]');
                if (edit) {
                    edit.focus({ preventScroll: true });
                }
            });

            // Deferred wire:model values may not have reached the server when
            // CSRF rejects the save. Capture the DOM, not the last snapshot.
            // Tab-local storage never crosses profiles; restoreDraft also gates
            // the caller server-side. A draft expires after one day.
            const draftKey = `two:profile-draft:${$wire.el.dataset.profileId}`;
            const keepDraft = () => {
                if (!$wire.el.querySelector('[data-testid="profile-edit-form"]')) {
                    return true;
                }

                try {
                    sessionStorage.setItem(draftKey, JSON.stringify({
                        version: 1,
                        savedAt: Date.now(),
                        bio: $wire.el.querySelector('#bio').value,
                        gamesText: $wire.el.querySelector('#games').value,
                        timezone: $wire.el.querySelector('#timezone').value,
                    }));
                    return true;
                } catch {
                    // Storage can be disabled or full. Never navigate away
                    // with unpreserved input: offer an explicit copy-first exit.
                    const warning = $wire.el.querySelector('[data-testid="profile-draft-unavailable"]');
                    warning.hidden = false;
                    warning.focus({ preventScroll: true });
                    return false;
                }
            };

            // Auth rechecks and another tab's logout can fire before a save
            // ever sends a request. Preserve input before those reloads too.
            // A quiet expiry goes through login rather than a guest render
            // that cannot restore this owner's draft; an explicit sign-out
            // in another tab stashes the draft and stays on the open form —
            // never auto-starting OAuth, where the pinned Discord driver's
            // prompt=none could silently complete an existing grant and sign
            // the shared browser back in with no login click (TOG-9355
            // review). Only an explicit login action leaves this page after
            // a sign-out. Ignore unmounted components.
            //
            // TOG-9355: the expiry branch starts abandoning this document —
            // the layout reloads when no form is open, this listener stashes
            // then navigates to login when one is. A restore still in flight
            // must not consume the only stored copy for a document about to
            // be replaced; the replacement page needs it. keepDraft is
            // synchronous DOM capture, so it lands before a slow restore
            // response in either branch.
            //
            // TOG-9355 review: the 419 request hook below is an independent
            // navigation path — after another tab signs out, Save/Cancel sends
            // the stale CSRF token and gets a 419 without any before-auth
            // reload firing first. Remember an explicit sign-out for the page
            // lifetime so that hook can stash and stay too, instead of
            // auto-starting OAuth where prompt=none could silently sign the
            // shared browser back in. Quiet expiry keeps login recovery.
            let authAbandoned = false;
            let explicitSignOut = false;
            document.addEventListener('two:before-auth-reload', (event) => {
                authAbandoned = true;
                if (event.detail?.reason === 'signed-out') {
                    explicitSignOut = true;
                }
                if (!$wire.el.isConnected || !$wire.el.querySelector('[data-testid="profile-edit-form"]')) {
                    return;
                }

                event.preventDefault();
                if (!keepDraft()) {
                    return;
                }
                if (event.detail?.reason === 'signed-out') {
                    return;
                }
                window.location.assign($wire.el.dataset.loginUrl);
            });

            // After an explicit sign-out in another tab, Save/Cancel is
            // not an explicit login action: stash and stay on the open
            // form instead of auto-starting OAuth, where the pinned
            // Discord driver's prompt=none could silently complete an
            // existing grant and sign the shared browser back in with no
            // login click (TOG-9355 review). Quiet-expiry 419s keep the
            // login recovery below.
            $wire.$hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status !== 419) {
                        return;
                    }

                    preventDefault();
                    if (keepDraft() && !explicitSignOut) {
                        window.location.assign($wire.el.dataset.loginUrl);
                    }
                });
            });

            // Covers the component's guest-session banner too, when CSRF
            // remained valid but authentication expired before save().
            $wire.el.addEventListener('click', (event) => {
                if (event.target.closest('[data-profile-login]') && !keepDraft()) {
                    event.preventDefault();
                }
            });

            // TOG-9355 review: a restore that failed earlier kept the stored
            // copy, but the later genuine save or explicit cancel never
            // retired it — the next page load restored the obsolete draft
            // over the newer saved text (or reopened cancelled input). The
            // server dispatches profile-draft-retired only on those terminal
            // discards — never on a write failure, validation refusal, or
            // early restored-save retry — so consuming it here keeps the only
            // stored copy recoverable until it is truly spent. sessionStorage
            // is per-tab, so the saving tab retires exactly its own copy.
            $wire.on('profile-draft-retired', () => {
                try {
                    sessionStorage.removeItem(draftKey);
                } catch {}
            });

            const restoreDraft = async () => {
                let draft;
                try {
                    const stored = sessionStorage.getItem(draftKey);
                    if (!stored) return;
                    draft = JSON.parse(stored);
                    if ($wire.el.dataset.draftOwner !== '1'
                        || draft?.version !== 1
                        || !Number.isFinite(draft.savedAt)
                        || draft.savedAt > Date.now()
                        || Date.now() - draft.savedAt > 24 * 60 * 60 * 1000
                        || !['bio', 'gamesText', 'timezone'].every(field => typeof draft[field] === 'string')) {
                        sessionStorage.removeItem(draftKey);
                        return;
                    }
                } catch {
                    try { sessionStorage.removeItem(draftKey); } catch {}
                    return;
                }

                try {
                    await $wire.restoreDraft(draft.bio, draft.gamesText, draft.timezone);
                    // A restore that resolves after auth-driven abandonment
                    // started is landing in a document about to be replaced.
                    // Keep the stored copy so the replacement page restores;
                    // consuming it here orphans the unsaved recovered form.
                    if (!authAbandoned) {
                        sessionStorage.removeItem(draftKey);
                    }
                } catch {
                    // An interrupted restore must not consume the only copy.
                    // A later page load can retry; never auto-save a draft.
                }
            };
            restoreDraft();
        </script>
    @endscript
</div>
