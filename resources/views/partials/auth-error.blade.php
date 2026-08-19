{{--
    Shown when a Discord sign-in did not work. Structure and accessibility only —
    the Designer owns how this looks and what it says (TWO-26); the strings live in
    lang/en/auth-discord.php. What matters here is that a member always gets a
    sentence instead of a stack trace, and that a screen reader announces it.
--}}
@if (session()->has('auth_error'))
    <div role="alert" data-testid="auth-error">
        <p>{{ __('auth-discord.'.session('auth_error')) }}</p>

        @if (session('auth_error') !== 'not_a_member')
            <a href="{{ route('login') }}">Try signing in again</a>
        @endif
    </div>
@endif
