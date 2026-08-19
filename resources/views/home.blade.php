{{--
    Placeholder. The real landing page — what TWO is, live member counts, real
    screenshots, one tracked join button — is TWO-28, built to the Designer's spec.
    Delete this file wholesale when that lands; nothing should be salvaged from it
    except the two things below, which the login flow depends on: the sign-in
    entry point and the failed-sign-in message.
--}}
<x-layouts.app title="Together We Own">
    <h1>Together We Own</h1>
    <p>The website scaffold is running. The landing page is not built yet.</p>

    @include('partials.auth-error')

    @auth
        <a href="{{ route('profile') }}">Your profile</a>
    @else
        <a href="{{ route('login') }}" data-testid="discord-login">Sign in with Discord</a>
    @endauth
</x-layouts.app>
