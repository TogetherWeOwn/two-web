{{--
    Direction A, "The Lobby Ledger" (TOG-1333).

    The view owns presentation only. Durable copy and chronology live in
    HomePageContent so a visual pivot does not require extracting content from a
    Blade template. Live counts, ranks and moderator-published items stay in the
    controller's existing data path.

    This first staging iteration deliberately uses documentary typography and
    ruled sections rather than fabricated community imagery. Featured content is
    the honest artifact path when moderators have a sourced item to publish.
--}}
<x-layouts.app title="Together We Own — the lobby is open" scheme="ledger">
    <div class="min-h-full bg-ledger-paper text-ledger-ink">
        <div class="h-2 bg-brand" aria-hidden="true"></div>

        <header class="mx-auto max-w-7xl px-5 pt-7 md:px-8 lg:px-12">
            <div class="flex items-center justify-between gap-6 border-b-2 border-ledger-ink pb-4 text-xs font-bold uppercase tracking-widest">
                <a href="{{ route('home') }}" class="hover:text-brand">{{ $content['masthead']['name'] }}</a>
                <span>{{ $content['masthead']['established'] }}</span>
            </div>

            <nav aria-label="Homepage sections" class="hidden items-center gap-10 border-b border-ledger-rule py-4 md:flex">
                <a href="#lobby" class="font-semibold hover:text-brand">The lobby</a>
                <a href="#history" class="font-semibold hover:text-brand">Our history</a>
                <a href="#ranks" class="font-semibold hover:text-brand">Ranks</a>
                <a href="#invitation" class="font-semibold hover:text-brand">How to join</a>
                <a href="{{ route('join') }}"
                   class="ml-auto inline-flex min-h-11 items-center justify-center bg-brand px-5 font-bold uppercase tracking-wide text-on-brand hover:bg-brand-hover active:bg-brand-active">
                    Join Discord
                </a>
            </nav>
        </header>

        <div id="lobby" class="mx-auto max-w-7xl px-5 pb-12 pt-12 md:px-8 md:pt-16 lg:px-12">
            @include('partials.auth-error')

            <section aria-labelledby="hero-heading" class="grid gap-10 border-b-2 border-ledger-ink pb-14 lg:grid-cols-12 lg:items-center lg:gap-14">
                <div class="lg:col-span-6">
                    <p class="text-xs font-bold uppercase tracking-widest">{{ $content['masthead']['strapline'] }}</p>
                    <h1 id="hero-heading" class="u-ledger-display mt-7 max-w-3xl text-5xl leading-none md:text-6xl">
                        {{ $content['hero']['title'] }}
                    </h1>
                    <p class="mt-7 max-w-2xl text-xl leading-relaxed">{{ $content['hero']['lead'] }}</p>
                    <p class="mt-2 max-w-2xl text-xl leading-relaxed">{{ $content['hero']['promise'] }}</p>

                    <div class="mt-9 flex flex-wrap items-center gap-x-5 gap-y-3">
                        <a href="{{ route('join') }}"
                           data-testid="discord-join"
                           class="inline-flex min-h-11 items-center justify-center bg-ledger-ink px-6 font-bold uppercase tracking-wide text-ledger-paper hover:bg-brand active:bg-brand-active">
                            {{ $content['hero']['action'] }} <span aria-hidden="true">→</span>
                        </a>

                        @if ($counts->hasMemberCount())
                            <p class="text-sm">
                                <span class="u-numeric font-bold">{{ $counts->memberCount }}</span> members
                                @if ($counts->hasOnlineCount())
                                    · <span class="font-bold">{{ $counts->onlineCount }}</span> online
                                @endif
                                @if ($counts->isStale())
                                    · as of <time datetime="{{ $counts->countsUpdatedAt->toIso8601String() }}">{{ $counts->countsUpdatedAt->format('H:i') }}</time>
                                @endif
                            </p>
                        @endif
                    </div>
                </div>

                <div class="lg:col-span-6">
                    @if ($featured->isNotEmpty())
                        <div data-testid="featured-content" class="border-2 border-ledger-ink bg-ledger-artifact p-5 md:p-8">
                            <p class="text-xs font-bold uppercase tracking-widest">From the community team</p>
                            @foreach ($featured as $item)
                                <article data-testid="featured-item" class="mt-6 border-t border-ledger-rule pt-5 first:mt-4">
                                    <h2 class="u-ledger-display text-3xl leading-tight">
                                        @if ($item->url)
                                            <a href="{{ $item->url }}" class="underline decoration-2 underline-offset-4 hover:text-brand">{{ $item->title }}</a>
                                        @else
                                            {{ $item->title }}
                                        @endif
                                    </h2>
                                    @if ($item->body)
                                        <p class="mt-3 max-w-2xl text-lg leading-relaxed">{{ $item->body }}</p>
                                    @endif
                                    @if ($item->image_url)
                                        <img src="{{ $item->image_url }}" alt="" loading="lazy" decoding="async" class="mt-5 w-full border border-ledger-rule">
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <div class="flex min-h-80 flex-col justify-between border-2 border-ledger-ink bg-ledger-artifact p-6 md:min-h-96 md:p-9">
                            <p class="text-xs font-bold uppercase tracking-widest">The archive is being opened carefully</p>
                            <div>
                                <p class="u-ledger-display max-w-lg text-4xl leading-tight">Real community artifacts belong here.</p>
                                <p class="mt-3 max-w-md italic leading-relaxed">Dated and sourced — never stock art, never a generated gamer.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <section aria-labelledby="activity-heading" class="border-b-2 border-ledger-ink py-10 md:py-12">
                <div class="flex flex-col justify-between gap-2 md:flex-row md:items-baseline">
                    <h2 id="activity-heading" class="text-xs font-bold uppercase tracking-widest">{{ $content['activity']['eyebrow'] }}</h2>
                    <p class="max-w-xl italic text-ledger-muted">{{ $content['activity']['note'] }}</p>
                </div>
                <dl class="mt-7 border-y border-ledger-rule">
                    @foreach (['quiet', 'honest'] as $row)
                        <div class="grid gap-2 border-b border-ledger-rule px-1 py-5 last:border-b-0 md:grid-cols-4 md:gap-8">
                            <dt class="font-bold uppercase">{{ $content['activity'][$row]['label'] }}</dt>
                            <dd class="text-lg leading-relaxed md:col-span-3">{{ $content['activity'][$row]['description'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section id="ranks" aria-labelledby="ranks-heading" class="grid gap-10 border-b-2 border-ledger-ink py-12 lg:grid-cols-12 lg:items-center">
                <div class="lg:col-span-5">
                    <p class="text-xs font-bold uppercase tracking-widest">{{ $content['ranks']['eyebrow'] }}</p>
                    <h2 id="ranks-heading" class="u-ledger-display mt-7 max-w-2xl text-4xl leading-tight md:text-5xl">
                        {{ $content['ranks']['title'] }}
                    </h2>
                    <p class="mt-5 max-w-xl text-xl leading-relaxed">{{ $content['ranks']['body'] }}</p>
                </div>

                <div class="lg:col-span-7">
                    @php
                        $rankNames = $ranks !== []
                            ? array_map(static fn ($rank) => $rank->label, $ranks)
                            : ['Prospect', 'Member', 'Soldier', 'Veteran', 'Legend'];
                    @endphp
                    <ol class="grid grid-cols-5" aria-label="Community rank progression">
                        @foreach ($rankNames as $index => $rankName)
                            <li class="relative flex min-w-0 flex-col items-center text-center">
                                @if (! $loop->first)
                                    <span class="absolute right-1/2 top-3 h-0.5 w-full bg-ledger-ink" aria-hidden="true"></span>
                                @endif
                                <span class="relative z-10 size-6 rounded-full border-2 border-ledger-ink {{ $loop->first ? 'bg-brand' : 'bg-ledger-paper' }}" aria-hidden="true"></span>
                                <span class="mt-4 max-w-full text-xs font-semibold uppercase sm:text-sm">{{ $rankName }}</span>
                                @if ($ranks !== [] && isset($ranks[$index]))
                                    @if ($ranks[$index]->isUnclaimed())
                                        <span class="mt-1 text-xs text-ledger-muted">unclaimed</span>
                                    @elseif ($ranks[$index]->hasCount())
                                        <span class="u-numeric mt-1 text-xs text-ledger-muted">{{ $ranks[$index]->memberCount }}</span>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section id="history" aria-labelledby="history-heading" class="border-b-2 border-ledger-ink py-12">
                <p class="text-xs font-bold uppercase tracking-widest">{{ $content['history']['eyebrow'] }}</p>
                <h2 id="history-heading" class="u-ledger-display mt-7 text-4xl leading-tight md:text-5xl">{{ $content['history']['title'] }}</h2>
                <ol class="mt-9 grid border-t-2 border-ledger-ink md:grid-cols-4">
                    @foreach ($content['history']['eras'] as $era)
                        <li class="border-b border-ledger-rule py-6 md:border-b-0 md:border-r md:px-6 md:first:pl-0 md:last:border-r-0">
                            <p class="font-bold uppercase">{{ $era['label'] }}</p>
                            <p class="mt-3 leading-relaxed">{{ $era['description'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section id="invitation" aria-labelledby="invitation-heading" class="mt-12 grid gap-8 bg-ledger-ink p-7 text-ledger-paper md:grid-cols-12 md:items-end md:p-10">
                <div class="md:col-span-9">
                    <p class="text-xs font-bold uppercase tracking-widest">{{ $content['invitation']['eyebrow'] }}</p>
                    <h2 id="invitation-heading" class="u-ledger-display mt-6 max-w-4xl text-4xl leading-tight md:text-5xl">
                        {{ $content['invitation']['title'] }}
                    </h2>
                </div>
                <div class="md:col-span-3 md:text-right">
                    <a href="{{ route('join') }}"
                       class="inline-flex min-h-11 items-center justify-center bg-brand px-6 font-bold uppercase tracking-wide text-on-brand hover:bg-brand-hover active:bg-brand-active">
                        {{ $content['invitation']['action'] }} <span aria-hidden="true">→</span>
                    </a>
                </div>
            </section>

            <footer class="flex flex-col gap-4 pb-4 pt-10 text-sm md:flex-row md:items-center md:justify-between">
                <p>Together We Own · adult gaming community · founded 1998</p>
                <div class="flex flex-wrap items-center gap-4 md:gap-6">
                    <a href="{{ route('about') }}" class="font-semibold underline underline-offset-4 hover:text-brand">About</a>
                    <a href="{{ route('rules') }}" class="font-semibold underline underline-offset-4 hover:text-brand">House rules</a>
                    @auth
                        <a href="{{ route('profile') }}" class="font-semibold underline underline-offset-4 hover:text-brand">Your profile</a>
                    @else
                        <a href="{{ route('login') }}" data-testid="discord-login" class="font-semibold underline underline-offset-4 hover:text-brand">Log in with Discord</a>
                    @endauth
                </div>
            </footer>
        </div>
    </div>
</x-layouts.app>
