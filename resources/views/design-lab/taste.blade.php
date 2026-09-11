{{--
    Taste design lab (TOG-2200).

    This concept shares the production homepage's controller data and join flow,
    but explores a separate cold editorial system: asymmetric type, sharp rules,
    a single signal-orange accent and documentary use of real moderator content.
--}}
<x-layouts.app title="Together We Own | Taste design lab" scheme="taste">
    <div class="min-h-full overflow-hidden bg-taste-paper text-taste-ink">
        <header class="mx-auto flex min-h-20 max-w-7xl items-center justify-between gap-6 px-5 md:px-8 lg:px-12">
            <a href="{{ route('home') }}" class="u-taste-condensed text-xl hover:text-taste-signal">
                Together We Own
            </a>

            <nav aria-label="Taste concept navigation" class="hidden items-center gap-8 text-sm font-semibold md:flex">
                <a href="#welcome" class="hover:text-taste-signal">Welcome</a>
                <a href="#trust" class="hover:text-taste-signal">Trust</a>
                <a href="#history" class="hover:text-taste-signal">History</a>
                <a href="{{ route('join') }}"
                   class="inline-flex min-h-11 items-center justify-center bg-taste-ink px-5 text-taste-paper transition duration-slow ease-out-quick hover:-translate-y-1 hover:bg-taste-signal active:translate-y-0">
                    Join Discord
                </a>
            </nav>

            <a href="{{ route('join') }}"
               class="inline-flex min-h-11 items-center justify-center bg-taste-ink px-4 text-sm font-semibold text-taste-paper md:hidden">
                Join Discord
            </a>
        </header>

        <div id="welcome" class="border-y border-taste-line">
            <section aria-labelledby="taste-hero-heading" class="mx-auto grid min-h-[calc(100dvh-5rem)] max-w-7xl lg:grid-cols-12">
                <div class="u-taste-rise flex flex-col justify-between border-b border-taste-line px-5 py-10 md:px-8 md:py-14 lg:col-span-8 lg:border-b-0 lg:border-r lg:px-12 lg:py-16">
                    <div>
                        <p class="max-w-sm text-sm font-semibold uppercase tracking-widest text-taste-muted">
                            Gaming community, founded 1998
                        </p>
                        <h1 id="taste-hero-heading" class="u-taste-display mt-8 max-w-5xl text-6xl leading-none sm:text-7xl lg:text-8xl">
                            Arrive for a game.<br>
                            Stay because people remember you.
                        </h1>
                    </div>

                    <div class="mt-12 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
                        <p class="max-w-xl text-xl leading-relaxed sm:text-2xl">
                            An adult, voice-first gaming community. No application and no performance required.
                        </p>
                        <a href="{{ route('join') }}"
                           data-testid="discord-join"
                           class="group inline-flex min-h-12 shrink-0 items-center justify-between gap-8 bg-taste-signal px-6 font-bold text-white transition duration-slow ease-out-quick hover:-translate-y-1 hover:bg-taste-signal-hover active:translate-y-0">
                            Join Discord
                            <span class="transition duration-slow ease-out-quick group-hover:translate-x-1" aria-hidden="true">↗</span>
                        </a>
                    </div>
                </div>

                <aside aria-label="Community status" class="u-taste-rise-late grid bg-taste-night text-taste-paper lg:col-span-4 lg:grid-rows-2">
                    <div class="flex flex-col justify-between border-b border-taste-muted p-7 md:p-10">
                        <p class="text-sm font-semibold text-taste-fog">The room</p>
                        <div class="mt-12">
                            @if ($counts->hasMemberCount())
                                <p class="u-taste-display u-numeric text-7xl leading-none">{{ $counts->memberCount }}</p>
                                <p class="mt-3 text-lg">
                                    members
                                    @if ($counts->hasOnlineCount())
                                        <span> / {{ $counts->onlineCount }} online</span>
                                    @endif
                                </p>
                                @if ($counts->isStale())
                                    <p class="mt-2 text-sm text-taste-fog">
                                        Last confirmed <time datetime="{{ $counts->countsUpdatedAt->toIso8601String() }}">{{ $counts->countsUpdatedAt->format('H:i') }}</time>
                                    </p>
                                @endif
                            @else
                                <p class="u-taste-display max-w-xs text-4xl leading-tight">The doors stay open when the counter goes quiet.</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col justify-between p-7 md:p-10">
                        <p class="text-sm font-semibold text-taste-fog">The promise</p>
                        <blockquote class="mt-12 max-w-sm text-3xl font-semibold leading-tight">
                            “Small enough that people notice when you come back.”
                        </blockquote>
                    </div>
                </aside>
            </section>
        </div>

        @include('partials.auth-error')

        <section aria-labelledby="featured-heading" class="mx-auto max-w-7xl px-5 py-20 md:px-8 md:py-28 lg:px-12">
            @if ($featured->isNotEmpty())
                <h2 id="featured-heading" class="u-taste-display max-w-4xl text-5xl leading-none md:text-6xl">What the community team wants you to see.</h2>
                <div data-testid="featured-content" class="mt-12 grid gap-10 lg:grid-cols-12">
                    @foreach ($featured as $item)
                        <article data-testid="featured-item" class="border-t-4 border-taste-ink pt-5 {{ $loop->first ? 'lg:col-span-7' : 'lg:col-span-5' }}">
                            @if ($item->image_url)
                                <img src="{{ $item->image_url }}" alt="" loading="lazy" decoding="async" class="mb-6 aspect-video w-full object-cover grayscale transition duration-slow ease-out-quick hover:grayscale-0">
                            @endif
                            <h3 class="u-taste-display text-4xl leading-tight">
                                @if ($item->url)
                                    <a href="{{ $item->url }}" class="underline decoration-2 underline-offset-4 hover:text-taste-signal">{{ $item->title }}</a>
                                @else
                                    {{ $item->title }}
                                @endif
                            </h3>
                            @if ($item->body)
                                <p class="mt-4 max-w-xl text-lg leading-relaxed text-taste-muted">{{ $item->body }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                <div class="grid gap-8 border-t-4 border-taste-ink pt-8 lg:grid-cols-12 lg:items-end">
                    <h2 id="featured-heading" class="u-taste-display text-5xl leading-none md:text-6xl lg:col-span-8">
                        We do not invent a busy room for the homepage.
                    </h2>
                    <p class="max-w-md text-xl leading-relaxed text-taste-muted lg:col-span-4">
                        Moderator-published community updates appear here when there is something real to share.
                    </p>
                </div>
            @endif
        </section>

        <section id="trust" aria-labelledby="trust-heading" class="bg-taste-fog">
            <div class="mx-auto grid max-w-7xl lg:grid-cols-12">
                <div class="border-b border-taste-line px-5 py-20 md:px-8 md:py-28 lg:col-span-5 lg:border-b-0 lg:border-r lg:px-12">
                    <h2 id="trust-heading" class="u-taste-display text-5xl leading-none md:text-6xl">Trust is earned in the room.</h2>
                    <p class="mt-7 max-w-lg text-xl leading-relaxed text-taste-muted">
                        Everyone starts as a Prospect. Time, familiarity and showing up move you forward.
                    </p>
                </div>

                <ol class="lg:col-span-7" aria-label="Community rank progression">
                    @php
                        $rankNames = $ranks !== []
                            ? array_map(static fn ($rank) => $rank->label, $ranks)
                            : ['Prospect', 'Member', 'Soldier', 'Veteran', 'Legend'];
                    @endphp
                    @foreach ($rankNames as $index => $rankName)
                        <li class="grid grid-cols-12 items-center border-b border-taste-line px-5 py-6 last:border-b-0 md:px-8 lg:px-10">
                            <span class="u-numeric col-span-2 text-sm text-taste-muted">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="u-taste-condensed col-span-7 text-3xl uppercase">{{ $rankName }}</span>
                            @if ($ranks !== [] && isset($ranks[$index]))
                                @if ($ranks[$index]->isUnclaimed())
                                    <span class="col-span-3 text-right text-sm text-taste-muted">unclaimed</span>
                                @elseif ($ranks[$index]->hasCount())
                                    <span class="u-numeric col-span-3 text-right text-lg font-semibold">{{ $ranks[$index]->memberCount }}</span>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section id="history" aria-labelledby="history-heading" class="mx-auto max-w-7xl px-5 py-20 md:px-8 md:py-28 lg:px-12">
            <h2 id="history-heading" class="u-taste-display max-w-5xl text-6xl leading-none md:text-7xl">Twenty-eight years, one continuous room.</h2>
            <ol class="mt-14 grid gap-10 border-t-4 border-taste-ink sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($content['history']['eras'] as $era)
                    <li class="pt-5">
                        <p class="u-taste-condensed text-2xl uppercase">{{ $era['label'] }}</p>
                        <p class="mt-3 text-lg leading-relaxed text-taste-muted">{{ $era['description'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        <section aria-labelledby="invitation-heading" class="bg-taste-signal text-white">
            <div class="mx-auto grid max-w-7xl gap-10 px-5 py-20 md:px-8 md:py-24 lg:grid-cols-12 lg:items-end lg:px-12">
                <h2 id="invitation-heading" class="u-taste-display text-6xl leading-none md:text-7xl lg:col-span-9">
                    Your first evening can just be an evening.
                </h2>
                <div class="lg:col-span-3 lg:text-right">
                    <a href="{{ route('join') }}"
                       class="inline-flex min-h-12 items-center justify-center bg-taste-night px-6 font-bold text-white transition duration-slow ease-out-quick hover:-translate-y-1 active:translate-y-0">
                        Join Discord
                    </a>
                </div>
            </div>
        </section>

        <footer class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-10 text-sm md:flex-row md:items-center md:justify-between md:px-8 lg:px-12">
            <p>Together We Own / adult gaming community / founded 1998</p>
            <div class="flex flex-wrap gap-6">
                <a href="{{ route('home') }}" class="font-semibold underline underline-offset-4 hover:text-taste-signal">Production homepage</a>
                @auth
                    <a href="{{ route('profile') }}" class="font-semibold underline underline-offset-4 hover:text-taste-signal">Your profile</a>
                @else
                    <a href="{{ route('login') }}" data-testid="discord-login" class="font-semibold underline underline-offset-4 hover:text-taste-signal">Log in with Discord</a>
                @endauth
            </div>
        </footer>
    </div>
</x-layouts.app>
