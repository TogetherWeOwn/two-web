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
                @if (session('join_result') === 'already_member')
                    {{-- TOG-7318: re-invite. One-click success lands on this
                         page, so already_member members who left the server
                         need the way back in here. Links the database-free
                         /discord funnel (see join.blade.php for the reasoning). --}}
                    <a href="{{ route('discord') }}"
                       data-testid="reinvite-link"
                       class="underline">
                        {{ __('join.reinvite') }}
                    </a>
                @endif
            </p>
        </div>
    @endif
    <livewire:member-profile :member="$member" :stats="$stats" />

    {{--
        Self-service data (TOG-8705, runbook docs/moderator-export-deletion.md).
        Owner-only: anyone else's page never shows this section, and the
        download/request routes take no id — both read the caller, so there is
        nothing to smuggle. Plain links and forms, no Livewire: the download
        is a file and the request is a single POST.
    --}}
    @if (auth()->user()?->is($member))
        <div class="mx-auto max-w-6xl px-4 pb-8 md:px-6 md:pb-12 lg:px-8">
            <section class="rounded-lg border border-line bg-surface p-5 md:p-6"
                     aria-labelledby="your-data-heading"
                     data-testid="profile-data-section">
                <h2 id="your-data-heading" class="text-xl font-semibold text-ink">Your data</h2>
                <p class="mt-2 text-sm text-ink-muted">Your profile and RSVPs, as the site holds them. The download is generated when you click it — no copy is kept.</p>

                @if (session('data_request_status'))
                    <p class="mt-4 rounded-lg border border-line bg-raised p-4 text-sm text-ink"
                       role="status"
                       data-testid="profile-data-request-status">
                        {{ session('data_request_status') }}
                    </p>
                @endif

                <div class="mt-4">
                    <a href="{{ route('profile.data-export') }}"
                       data-testid="profile-data-download"
                       class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised active:bg-surface">
                        Download my data (JSON)
                    </a>
                </div>

                @if ($pendingDataRequest ?? false)
                    <p class="mt-4 text-sm text-ink-muted" data-testid="profile-deletion-pending">
                        Deletion requested — a moderator will review it. Download anything you want to keep first.
                    </p>
                @else
                    <form method="POST" action="{{ route('profile.deletion-request') }}" class="mt-4" data-testid="profile-deletion-form">
                        @csrf
                        <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                            Request deletion
                        </button>
                    </form>
                    <p class="mt-2 text-sm text-ink-muted">Removes your profile and RSVPs after moderator review. Events you created stay up, unattributed. Your Discord server membership is untouched.</p>
                @endif
            </section>
        </div>
    @endif
</x-layouts.app>
