{{--
    Hallmark concept for TOG-2199. This route is additive and deliberately
    consumes the production homepage's content, counts, ranks and featured rows.
    The visual thesis is a community map: entry is a path, not a sales funnel.
--}}
<x-layouts.app title="Together We Own — Hallmark concept" scheme="hallmark" robots="noindex, nofollow" :styles="['resources/css/hallmark.css']">
    <div class="hallmark-page">
        <header class="hallmark-shell">
            <nav class="hallmark-nav" aria-label="Hallmark concept">
                <a href="{{ route('design-lab.hallmark') }}" class="hallmark-wordmark">
                    {{ $content['masthead']['name'] }}
                </a>
                <a href="{{ route('join') }}" class="hallmark-nav__cta">Come in</a>
            </nav>
        </header>

        <div class="hallmark-shell">
            @include('partials.auth-error')

            <section class="hallmark-hero" aria-labelledby="hallmark-heading">
                <p class="hallmark-hero__stamp">{{ $content['masthead']['established'] }} · {{ $content['masthead']['strapline'] }}</p>
                <h1 id="hallmark-heading">{{ $content['hero']['title'] }}</h1>
                <p class="hallmark-hero__lede">
                    {{ $content['hero']['lead'] }} {{ $content['hero']['promise'] }}
                </p>
                @if ($counts->hasMemberCount())
                    <div class="hallmark-live" aria-label="Current community count">
                        <span class="hallmark-live__number">{{ $counts->memberCount }}</span>
                        <span class="hallmark-live__label">members</span>
                        @if ($counts->hasOnlineCount())
                            <span class="hallmark-live__number">{{ $counts->onlineCount }}</span>
                            <span class="hallmark-live__label">online</span>
                        @endif
                        @if ($counts->isStale())
                            <span class="hallmark-live__date">as of <time datetime="{{ $counts->countsUpdatedAt->toIso8601String() }}">{{ $counts->countsUpdatedAt->format('H:i') }}</time></span>
                        @endif
                    </div>
                @endif
            </section>

            <section class="hallmark-map-section" aria-labelledby="map-heading">
                <div class="hallmark-map-section__intro">
                    <h2 id="map-heading">A route through the lobby.</h2>
                    <p>
                        No application. No interview. The community becomes legible by moving through it: arrive, join a room, return, earn trust.
                    </p>
                    <a href="{{ route('join') }}" data-testid="discord-join" class="hallmark-link">Take the first step →</a>
                </div>

                <div class="hallmark-map" aria-label="The path from arriving to belonging">
                    <article class="hallmark-map__node hallmark-map__node--door">
                        <h3>The door</h3>
                        <p>{{ $content['hero']['lead'] }}</p>
                    </article>
                    <article class="hallmark-map__node hallmark-map__node--voice">
                        <h3>{{ $content['activity']['eyebrow'] }}</h3>
                        <p>{{ $content['activity']['quiet']['description'] }}</p>
                    </article>
                    <article class="hallmark-map__node hallmark-map__node--trust">
                        <h3>{{ $content['ranks']['title'] }}</h3>
                        <p>{{ $content['ranks']['body'] }}</p>
                    </article>
                    <article class="hallmark-map__node hallmark-map__node--history">
                        <h3>{{ $content['history']['title'] }}</h3>
                        <p>{{ collect($content['history']['eras'])->pluck('label')->join(' → ') }}</p>
                    </article>
                    <article class="hallmark-map__node hallmark-map__node--home">
                        <h3>A place that notices.</h3>
                        <p>{{ $content['hero']['promise'] }}</p>
                    </article>
                </div>
            </section>

            <section class="hallmark-ranks" aria-labelledby="ranks-heading">
                <p id="ranks-heading" class="hallmark-ranks__copy">
                    <strong>{{ $content['ranks']['eyebrow'] }}.</strong>
                    The ladder records time and trust, not grind.
                </p>
                @php
                    $rankNames = $ranks !== []
                        ? array_map(static fn ($rank) => $rank->label, $ranks)
                        : ['Prospect', 'Member', 'Soldier', 'Veteran', 'Legend'];
                @endphp
                <ol aria-label="Community rank progression">
                    @foreach ($rankNames as $index => $rankName)
                        <li>
                            <span>{{ $rankName }}</span>
                            @if ($ranks !== [] && isset($ranks[$index]))
                                @if ($ranks[$index]->isUnclaimed())
                                    <span class="hallmark-ranks__value">unclaimed</span>
                                @elseif ($ranks[$index]->hasCount())
                                    <span class="hallmark-ranks__value">{{ $ranks[$index]->memberCount }} members</span>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($featured->isNotEmpty())
                <section class="hallmark-featured" data-testid="featured-content" aria-labelledby="featured-heading">
                    <h2 id="featured-heading">From the community team.</h2>
                    <div class="hallmark-featured__items">
                        @foreach ($featured as $item)
                            <article data-testid="featured-item">
                                <h3>
                                    @if ($item->url)
                                        <a href="{{ $item->url }}" class="hallmark-link">{{ $item->title }}</a>
                                    @else
                                        {{ $item->title }}
                                    @endif
                                </h3>
                                @if ($item->body)
                                    <p>{{ $item->body }}</p>
                                @endif
                                @if ($item->image_url)
                                    <img src="{{ $item->image_url }}" alt="" loading="lazy" decoding="async">
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="hallmark-invitation" aria-labelledby="invitation-heading">
                <h2 id="invitation-heading" class="hallmark-invitation__copy">{{ $content['invitation']['title'] }}</h2>
                <a href="{{ route('join') }}" class="hallmark-join">{{ $content['invitation']['action'] }} →</a>
            </section>

            <footer class="hallmark-close">
                <p class="hallmark-close__line">Come for a game. Return because somebody noticed.</p>
                <div class="hallmark-meta">
                    <p>Together We Own · adult gaming community · founded 1998</p>
                    @auth
                        <a href="{{ route('profile') }}">Your profile</a>
                    @else
                        <a href="{{ route('login') }}" data-testid="discord-login">Log in with Discord</a>
                    @endauth
                </div>
            </footer>
        </div>
    </div>
</x-layouts.app>
