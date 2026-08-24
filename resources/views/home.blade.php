{{--
    Placeholder. The real landing page — what TWO is, live member counts, real
    screenshots, one tracked join button — is TWO-28, built to the Designer's spec.
    Delete this file wholesale when that lands; nothing should be salvaged from it
    except the three things below: the sign-in entry point and the failed-sign-in
    message, which the login flow depends on, and the join link.

    TWO-28 owns what the join button looks like and where it sits. This plain link
    is only here so the journey is reachable — an unreachable front door converts
    nobody, and /join is the page that replaces the one thing the WordPress site
    does well.
--}}
<x-layouts.app title="Together We Own">
    <h1>Together We Own</h1>
    <p>The website scaffold is running. The landing page is not built yet.</p>

    @include('partials.auth-error')

    @auth
        <a href="{{ route('profile') }}">Your profile</a>
    @else
        <a href="{{ route('login') }}" data-testid="discord-login">Sign in with Discord</a>
        <a href="{{ route('join') }}" data-testid="join-link">{{ __('join.heading') }}</a>
    @endauth
</x-layouts.app>
