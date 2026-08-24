{{--
    The join page. Structure and accessibility only — the Designer owns how this
    looks and what it says (TWO-26); the strings live in lang/en/join.php.

    Three states, and the page never has nothing to offer:
      - one-click available  -> the button that adds them in a single approval
      - one-click unavailable -> the plain invite link, no consent screen at all
      - neither configured   -> a sentence saying so, rather than a dead link

    Whichever it is, an outcome from a previous attempt renders above it, so a
    member who was just added sees "you're in" and the way into the server rather
    than the button they already pressed.
--}}
<x-layouts.app title="Join Together We Own">
    <h1>{{ __('join.heading') }}</h1>

    @include('partials.join-result')

    @if ($oneClick)
        <a href="{{ route('join.redirect') }}" data-testid="one-click-join">{{ __('join.one_click') }}</a>
    @elseif ($inviteUrl !== null)
        {{-- No bot, so no consent screen: asking for `guilds.join` here would
             spend the member's approval and still end at this same link. --}}
        <a href="{{ $inviteUrl }}" data-testid="invite-link" rel="noopener">{{ __('join.invite') }}</a>
    @else
        <p data-testid="join-unconfigured">{{ __('join.unconfigured') }}</p>
    @endif
</x-layouts.app>
