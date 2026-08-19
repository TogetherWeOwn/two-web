{{--
    Placeholder. The real member profile — games, roles, join date, stats from the
    bot's event data — is TWO-29 and TWO-30, built to the Designer's spec. This
    page exists only so a successful Discord sign-in has somewhere to land and so
    the login journey can be tested end to end. Delete it wholesale when they land.
--}}
<x-layouts.app title="Your profile">
    <h1>Your profile</h1>

    <p>Signed in as {{ auth()->user()->display_name ?? auth()->user()->username }}.</p>

    @can('access-admin')
        <a href="/admin" data-testid="admin-link">Moderator admin</a>
    @endcan

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
</x-layouts.app>
