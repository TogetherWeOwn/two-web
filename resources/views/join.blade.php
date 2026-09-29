<x-layouts.app :title="__('join.heading')" :canonical="route('join')" :shareDescription="__('join.intro')">
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

            {{--
                Live lobby widget + static fallback (TOG-6928). The iframe is a
                live look, never the conversion path: the what-to-expect panel
                is always in the HTML, so when Discord's widget is unreachable —
                blocked embed, widget disabled server-side, bad guild id — the
                page still converts on the invite link. No JS detection: a
                cross-origin iframe failure is not reliably observable
                (onload fires on error pages too), so the fallback is static
                markup, not a script-swapped state. `loading="lazy"` keeps the
                third-party frame out of the first-paint path.
            --}}
            <div class="mt-12 grid gap-6 lg:grid-cols-2 lg:items-start">
                <section aria-labelledby="join-expect-heading"
                         data-testid="join-expect"
                         class="rounded-lg border border-line bg-surface p-5 md:p-6">
                    <h2 id="join-expect-heading" class="text-xl font-semibold text-ink">{{ __('join.expect_heading') }}</h2>
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-ink-muted">
                        @foreach (__('join.expect') as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                    <a href="{{ $inviteUrl }}"
                       data-testid="join-fallback-invite"
                       rel="noopener"
                       class="mt-4 inline-flex min-h-11 items-center justify-center rounded-md border border-line-strong px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised active:bg-surface">
                        {{ __('join.invite') }}
                    </a>
                </section>

                @if ($widgetUrl)
                    <section aria-labelledby="join-widget-heading"
                             class="rounded-lg border border-line bg-surface p-5 md:p-6">
                        <h2 id="join-widget-heading" class="text-xl font-semibold text-ink">{{ __('join.widget_title') }}</h2>
                        {{-- `height` reserves the box (no CLS); `referrerpolicy`
                             keeps Discord from learning which page the visitor is
                             on (same rule as third-party images, TOG-7473). --}}
                        <iframe src="{{ $widgetUrl }}"
                                title="{{ __('join.widget_title') }}"
                                height="400"
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                data-testid="join-widget"
                                class="mt-3 w-full rounded-md border border-line bg-raised"></iframe>
                        <p class="mt-3 text-sm text-ink-muted">{{ __('join.widget_note') }}</p>
                    </section>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
