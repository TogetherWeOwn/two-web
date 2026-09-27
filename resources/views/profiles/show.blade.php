<x-layouts.app :title="($member->display_name ?? $member->username).' — Member profile'">
    {{--
        The post-join confirmation (TOG-6229). JoinController::callback redirects
        here with a `join_result` flash; this page is the only thing that renders
        after it, so if it does not read the flash the member — back from the
        Discord app switch with no other context — gets no "you are in".
        Same success/alert split as join.blade.php.
    --}}
    @if (session('join_result'))
        @php($joinSuccess = in_array(session('join_result'), ['added', 'already_member'], true))
        <div class="mx-auto max-w-6xl px-4 pt-8 md:px-6 lg:px-8">
            <p class="rounded-lg border border-line bg-surface p-4 text-ink"
               role="{{ $joinSuccess ? 'status' : 'alert' }}"
               data-testid="join-result">
                {{ __('join.result.'.session('join_result')) }}
            </p>
        </div>
    @endif
    <livewire:member-profile :member="$member" :stats="$stats" />
</x-layouts.app>
