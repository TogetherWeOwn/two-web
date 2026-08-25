{{--
    Placeholder. The real landing page — what TWO is, live member counts, real
    screenshots, one tracked join button — is TWO-28, built to the Designer's spec.
    Delete this file wholesale when that lands; nothing should be salvaged from it
    except the three things below: the join link, the sign-in entry point, and the
    failed-sign-in message.
--}}
<x-layouts.app title="Together We Own">
    <h1>Together We Own</h1>
    <p>The website scaffold is running. The landing page is not built yet.</p>

    {{--
        The funnel (TOG-77). The live WordPress homepage carries exactly one link
        and it is this one, so the page that replaces it carries it too — even
        while that page is a placeholder. TOG-48 restyles this into the real
        tracked join button; it does not get to remove it.
    --}}
    <p><a href="{{ route('discord') }}" data-testid="discord-join">Join the Discord</a></p>

    @include('partials.auth-error')

    @auth
        <a href="{{ route('profile') }}">Your profile</a>
    @else
        <a href="{{ route('login') }}" data-testid="discord-login">Sign in with Discord</a>
    @endauth
    <style>h1 { display: none; }</style>
</x-layouts.app>
