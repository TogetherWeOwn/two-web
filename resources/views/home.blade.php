{{--
    The landing page (TOG-48). The top of the funnel, and the entire first
    impression — it exists to serve one number: new members joining.

    Everything visual here comes from the two-design tokens; everything verbal
    comes from two-design `docs/CONTENT.md` and `docs/COPY.md`. Neither is
    invented locally. Three things that look like omissions and are not:

    - **No screenshots.** The designer audited the server (CONTENT.md §2): a
      screenshot of #general shows ten messages across three months and reads as
      "this place is dead" rather than "this place is small". The original ask
      for "real screenshots" is overridden by that finding.
    - **No games list.** Genres and platforms only (CONTENT.md Decision 3b) —
      the genre roles are real and the game-specific ones are held by nobody.
    - **No member count anywhere in this file.** It is read from the bot's
      `web_v1.live_counts`, and when it is not there the block is omitted
      rather than zeroed.

    One primary button per screen, and on this page it is `route('discord')`.
--}}
<x-layouts.app title="Together We Own — a gaming clan since 1998">
    {{-- The hero. The violet is a flat band, never a gradient (BRAND.md), and
         it is the one tinted band on the page.

         The h1 is two words so it holds one line at 390px, which is what keeps
         the join button above the fold (COMPONENTS.md §12). If this copy ever
         grows past three lines at that width, the copy changes — not the type
         size. --}}
    <section class="bg-violet">
        <div class="mx-auto max-w-6xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <h1 class="u-display text-3xl text-ink md:text-5xl lg:text-6xl">Since 1998.</h1>

            <p class="mt-3 max-w-prose text-lg text-ink">
                Together We Own is a gaming clan that spent most of its life private.
                We opened the doors.
            </p>

            <p class="mt-3 max-w-prose text-ink-muted">
                18+, mostly North America, mostly evenings. Small enough that people
                know your name by the second time you show up.
            </p>

            {{-- The one primary action on the page. COMPONENTS.md §1.1 — crimson,
                 min-h-11, colour transitions only. `data-testid` is load-bearing:
                 tests/Feature/DiscordFunnelTest.php and the Dusk journey both
                 assert the funnel still has its door. --}}
            <div class="mt-8">
                <a href="{{ route('discord') }}"
                   data-testid="discord-join"
                   class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    Join the Discord
                </a>
            </div>

            {{-- The counts sit below the button, supporting the pitch rather than
                 being it (COPY.md). When the collector is dark this whole block
                 is absent: the headline and the button carry the section on
                 their own, which is exactly the degraded state in
                 COMPONENTS.md §7. Never a `0`, never a spinner. --}}
            @if ($counts->hasMemberCount())
                <p class="mt-4 text-sm text-ink-muted">
                    <span class="u-numeric text-ink">{{ $counts->memberCount }}</span>
                    members
                    @if ($counts->hasOnlineCount())
                        ·
                        <span class="text-online">
                            {{-- The dot is decorative; the word "online" beside it is what
                                 carries the meaning, because presence is never colour-only. --}}
                            <span class="inline-block size-1.5 rounded-full bg-online align-middle" aria-hidden="true"></span>
                            <span class="u-numeric">{{ $counts->onlineCount }}</span> online
                        </span>
                    @endif
                </p>

                @if ($counts->isStale())
                    {{-- Shown only past 10 minutes. A timestamp on a number read
                         forty seconds ago makes the fresh case look doubtful. --}}
                    <p class="mt-1 text-xs text-ink-muted">
                        as of <time datetime="{{ $counts->countsUpdatedAt->toIso8601String() }}">{{ $counts->countsUpdatedAt->format('H:i') }}</time>
                    </p>
                @endif
            @endif
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-16 md:px-6 lg:px-8">
        @include('partials.auth-error')

        {{-- The strongest section on the page: it answers "what actually happens
             if I click join" with something specific and true. The ladder is
             read from the bot, never hardcoded — and it renders all five rungs
             even where a rung has no headcount yet, because a ladder that got
             shorter would misdescribe the clan. --}}
        <section aria-labelledby="ladder-heading">
            <h2 id="ladder-heading" class="u-display text-2xl text-ink md:text-3xl">You start as a Prospect</h2>

            <p class="mt-4 max-w-prose text-ink-muted">
                Everyone does. Show up a few times, play some games, and you become a
                Member. After that there is a ladder that goes Soldier, Veteran,
                Legend — some of the people on it have been here since the clan was on
                a forum.
            </p>

            <p class="mt-3 max-w-prose text-ink-muted">
                There is no application and no interview. You just turn up.
            </p>

            @if ($ranks !== [])
                {{-- A list, not a table: it is a sequence of rungs with a count
                     each, and a screen reader announcing "list, 5 items" is the
                     right description of the ladder. --}}
                <ul role="list" class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($ranks as $rank)
                        <li class="flex items-baseline justify-between gap-4 rounded-lg border border-line bg-surface p-5">
                            <span class="font-medium text-ink">{{ $rank->label }}</span>
                            @if ($rank->isUnclaimed())
                                {{-- A real 0 is a fact — nobody holds Legend yet — but
                                     CONTENT.md §4 forbids a bare `0` without a sentence
                                     saying what it means, and the empty-state rule is to
                                     say what happens next. So the rung says it is open
                                     rather than printing a numeral that reads as a dead
                                     ladder. --}}
                                <span class="text-sm text-ink-muted">unclaimed</span>
                            @elseif ($rank->hasCount())
                                {{-- Null prints nothing at all — that one means we could
                                     not read the rung, not that it is empty. --}}
                                <span class="u-numeric text-lg text-ink-muted">{{ $rank->memberCount }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="play-heading" class="mt-16">
            <h2 id="play-heading" class="u-display text-2xl text-ink md:text-3xl">Squads, not raids</h2>

            <p class="mt-4 max-w-prose text-ink-muted">
                The voice rooms are Duos, Trios, Quads and Squads, which tells you most
                of what you need to know. Shooters mostly, some survival, some horror
                when somebody talks the rest of us into it.
            </p>

            <p class="mt-3 max-w-prose text-ink-muted">
                PC, Xbox, PlayStation, Switch. Nobody cares which.
            </p>

            {{-- Genres and platforms, not titles. Badges are rounded-sm, not
                 pills — COMPONENTS.md §6, "pill badges are the SaaS-template
                 tell". These are the genre and platform roles that people
                 actually hold; the game-specific roles are held by nobody, so
                 printing them would be printing a stale list. --}}
            <h3 class="mt-8 text-sm font-medium text-ink">What we play</h3>
            <ul role="list" class="mt-3 flex flex-wrap gap-2">
                @foreach (['Shooters', 'Survival', 'Horror'] as $genre)
                    <li class="inline-flex items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">{{ $genre }}</li>
                @endforeach
            </ul>

            <h3 class="mt-6 text-sm font-medium text-ink">Where we play</h3>
            <ul role="list" class="mt-3 flex flex-wrap gap-2">
                @foreach (['PC', 'Xbox', 'Switch', 'Mobile', 'PlayStation'] as $platform)
                    <li class="inline-flex items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">{{ $platform }}</li>
                @endforeach
            </ul>
        </section>

        {{-- The section that makes everything else on the page credible. It
             says out loud that we are small, and it disqualifies exactly the
             visitors who would have left anyway. Deliberately carries no
             number: the honest framing works whether or not the counter above
             it rendered. --}}
        <section aria-labelledby="small-heading" class="mt-16">
            <h2 id="small-heading" class="u-display text-2xl text-ink md:text-3xl">
                We are small, and we are not pretending otherwise
            </h2>

            <p class="mt-4 max-w-prose text-ink-muted">
                Some nights the lobby is busy and some nights it is three of us and a
                bad idea. If you want a server with a thousand people talking at once,
                there are plenty and you should go there.
            </p>

            <p class="mt-3 max-w-prose text-ink-muted">
                If you want somewhere people notice you came back, this is that.
            </p>
        </section>

        {{-- The footer CTA. The primary button budget is one per screen and the
             hero spent it, so this one is the secondary variant: border-line-strong,
             not border-line, because it is a control boundary and has to clear
             3:1 (WCAG 1.4.11). COMPONENTS.md §1.2 calls using border-line here
             "the most likely accessibility bug in this build". --}}
        <section aria-labelledby="door-heading" class="mt-16 border-t border-line pt-10">
            <h2 id="door-heading" class="u-display text-2xl text-ink">The door is open</h2>

            <div class="mt-5 flex flex-wrap items-center gap-4">
                <a href="{{ route('discord') }}"
                   class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong
                          bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick
                          hover:border-ink-muted hover:bg-raised active:bg-surface">
                    Join the Discord
                </a>

                {{-- The sign-in entry point, preserved from the placeholder. It is
                     not a call to action — you sign in *after* you are in the
                     server — so it is the quiet variant and it sits beside the
                     join button rather than competing with it. --}}
                @auth
                    <a href="{{ route('profile') }}"
                       class="inline-flex min-h-11 items-center rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                        Your profile
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       data-testid="discord-login"
                       class="inline-flex min-h-11 items-center rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                        Log in with Discord
                    </a>
                @endauth
            </div>
        </section>
    </div>
</x-layouts.app>
