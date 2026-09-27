{{--
    Share tags (TOG-6793). Same opt-in as the event page: the canonical is
    always the shareable member URL, so `/profile` and `/members/{user}` never
    present as two pages. The description stays generic on purpose — the only
    member data in the tags is the display name already in the title.
--}}
<x-layouts.app :title="($member->display_name ?? $member->username).' — Member profile'"
               :canonical="route('profiles.show', $member)"
               :shareDescription="'A member of Together We Own.'">
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
    {{--
        Copy-link toast (TOG-6926). Lives in the page, not the Livewire
        component: a component re-render (edit/save) morphs the header and
        would wipe a toast inside it mid-announcement. Hidden until
        resources/js/profile-copy-link.js fills and reveals it; `role="status"`
        announces the confirmation without stealing focus.
    --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex justify-center px-4">
        <p class="hidden max-w-md rounded-lg border border-line bg-online-quiet p-4 text-sm text-ink shadow-overlay"
           role="status"
           data-testid="profile-copy-toast"></p>
    </div>
    {{--
        Page script (TOG-6926). A second @vite is fine — the plugin emits tags
        wherever the directive sits — and `type="module"` defers by default, so
        this never blocks first paint. Kept off the layout on purpose: the global
        bundle is pinned import-free (see AssetCompressionTest) and only profile
        pages should download this.
    --}}
    @vite('resources/js/profile-copy-link.js')
</x-layouts.app>
