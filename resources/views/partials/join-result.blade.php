{{--
    What happened on the last join attempt. Included by join.blade.php, so it
    inherits $inviteUrl from there.

    `added` and `already_member` are both successes and both get the way into the
    server. Telling somebody who is already a member that something went wrong
    would be a lie, and a dead end. Everything else gets the invite link, which
    reaches exactly the same place with one more click.

    role=status for a success and role=alert for a failure: a screen reader
    should interrupt somebody for "we could not add you", not for "you're in".
--}}
@if (session()->has('join_result'))
    @php($result = session('join_result'))
    @php($succeeded = in_array($result, ['added', 'already_member'], true))

    <div role="{{ $succeeded ? 'status' : 'alert' }}" data-testid="join-result">
        <p>{{ __('join.result.'.$result) }}</p>

        @if ($inviteUrl !== null)
            <a
                href="{{ $inviteUrl }}"
                data-testid="{{ $succeeded ? 'server-link' : 'invite-link' }}"
                rel="noopener"
            >{{ $succeeded ? __('join.open_server') : __('join.invite') }}</a>
        @endif
    </div>
@endif
