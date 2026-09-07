<x-layouts.app :title="($member->display_name ?? $member->username).' — Member profile'">
    <main>
        <header>
            @if ($member->avatar)
                <img src="{{ $member->avatar }}" alt="" width="96" height="96">
            @endif

            <p>{{ auth()->user()->is($member) ? 'Your profile' : 'Member profile' }}</p>
            <h1>{{ $member->display_name ?? $member->username }}</h1>
            <p>{{ '@'.$member->username }} · <span>From Discord</span></p>

            @if ($stats->joinedAt ?? $member->discord_joined_at)
                <p>
                    Joined {{ ($stats->joinedAt ?? $member->discord_joined_at)->format('F Y') }}
                    · <span>From Discord</span>
                </p>
            @endif

            @if ($stats->available && $stats->rankKey)
                <p>{{ str($stats->rankKey)->headline() }} · <span>From Discord</span></p>
            @endif

            @can('access-admin')
                <a href="/admin" data-testid="admin-link">Moderator admin</a>
            @endcan
        </header>

        <section aria-labelledby="about-heading">
            <h2 id="about-heading">About</h2>
            <p>{{ $profile->bio ?: 'This member has not added a bio yet.' }}</p>
        </section>

        <section aria-labelledby="games-heading">
            <h2 id="games-heading">Games</h2>
            @if ($profile->games !== [])
                <ul>
                    @foreach ($profile->games as $game)
                        <li>{{ $game }}</li>
                    @endforeach
                </ul>
            @else
                <p>No games added yet.</p>
            @endif
        </section>

        @if ($profile->timezone)
            <section aria-labelledby="timezone-heading">
                <h2 id="timezone-heading">Timezone</h2>
                <p>{{ $profile->timezone }}</p>
            </section>
        @endif

        <section aria-labelledby="activity-heading">
            <h2 id="activity-heading">Activity</h2>

            @if (! $stats->available)
                <p role="status">Activity is taking a breather. The rest of this profile is still here.</p>
            @elseif ($stats->milestones === [])
                <p>No activity milestones to show yet.</p>
            @else
                <ol>
                    @foreach ($stats->milestones as $milestone)
                        <li>
                            {{ str($milestone->type)->replace('_', ' ')->headline() }}
                            @if ($milestone->detail)
                                — {{ str($milestone->detail)->headline() }}
                            @endif
                            <time datetime="{{ $milestone->occurredAt->toIso8601String() }}">
                                {{ $milestone->occurredAt->format('j M Y') }}
                            </time>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        @can('updateProfile', $member)
            <section aria-labelledby="edit-heading">
                <h2 id="edit-heading">Edit your profile</h2>
                <p>Your name, avatar, roles, join date and rank come from Discord and cannot be changed here.</p>

                <form method="POST" action="{{ route('profiles.update', $member) }}" data-testid="profile-edit-form">
                    @csrf
                    @method('PATCH')

                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" maxlength="1000">{{ old('bio', $profile->bio) }}</textarea>
                    @error('bio') <p role="alert">{{ $message }}</p> @enderror

                    <label for="games">Games, one per line</label>
                    <textarea id="games" name="games_text">{{ old('games_text', implode("\n", $profile->games)) }}</textarea>
                    @error('games') <p role="alert">{{ $message }}</p> @enderror

                    <label for="timezone">Timezone</label>
                    <input id="timezone" name="timezone" value="{{ old('timezone', $profile->timezone) }}" list="timezones">
                    <datalist id="timezones">
                        @foreach (timezone_identifiers_list() as $timezone)
                            <option value="{{ $timezone }}"></option>
                        @endforeach
                    </datalist>
                    @error('timezone') <p role="alert">{{ $message }}</p> @enderror

                    <button type="submit">Save profile</button>
                </form>
            </section>
        @endcan

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </main>
</x-layouts.app>
