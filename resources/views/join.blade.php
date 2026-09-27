<x-layouts.app title="Join Together We Own" :canonical="route('join')" :shareDescription="__('join.intro')">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <h1 class="u-display text-3xl text-ink md:text-5xl">{{ __('join.heading') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('join.intro') }}</p>

            @if (session('join_result'))
                @php($success = in_array(session('join_result'), ['added', 'already_member'], true))
                <p class="mt-6 rounded-lg border border-line bg-surface p-4 text-ink"
                   role="{{ $success ? 'status' : 'alert' }}"
                   data-testid="join-result">
                    {{ __('join.result.'.session('join_result')) }}
                </p>
            @endif

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join.redirect') }}"
                   data-testid="one-click-join"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('join.one_click') }}
                </a>
                <a href="{{ $inviteUrl }}"
                   data-testid="invite-link"
                   rel="noopener"
                   class="underline text-ink-muted">
                    {{ __('join.invite') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
