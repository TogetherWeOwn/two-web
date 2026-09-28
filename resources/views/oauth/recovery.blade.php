<x-layouts.app :title="$title">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <h1 class="u-display text-3xl text-ink md:text-5xl">{{ $title }}</h1>
            <p class="mt-6 rounded-lg border border-line bg-surface p-4 text-ink"
               role="alert"
               data-testid="oauth-recovery">
                {{ $message }}
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ $retryUrl }}"
                   data-testid="oauth-recovery-retry"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ $retryLabel }}
                </a>
                <a href="{{ $inviteUrl }}"
                   data-testid="invite-link"
                   class="inline-flex min-h-11 items-center text-ink underline underline-offset-4">
                    {{ __('join.invite') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
