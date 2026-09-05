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

    {{--
        What moderators put here from /admin (TOG-54). The section is omitted
        entirely when nothing is live, rather than rendered empty: an empty
        landmark with a heading and no content is noise for a screen reader, and
        the join link above must stay the page's one call to action.

        Headings are h2 under the page h1 — axe checks heading-order at both
        widths in ci/a11y.mjs, and skipping a level is a WCAG failure, not a
        style preference.
    --}}
    @if ($featured->isNotEmpty())
        <section aria-labelledby="featured-heading" data-testid="featured-content">
            <h2 id="featured-heading">From the community team</h2>

            @foreach ($featured as $item)
                <article data-testid="featured-item">
                    <h3>
                        @if ($item->url)
                            {{-- The whole heading is the link target, so the
                                 accessible name of the link is the headline
                                 rather than a bare "read more". --}}
                            <a href="{{ $item->url }}">{{ $item->title }}</a>
                        @else
                            {{ $item->title }}
                        @endif
                    </h3>

                    @if ($item->body)
                        <p>{{ $item->body }}</p>
                    @endif

                    @if ($item->image_url)
                        {{-- alt is intentionally empty: the headline beside it
                             already carries the meaning, so announcing the image
                             too would repeat it. A decorative image with a
                             non-empty alt is the more common a11y defect. --}}
                        <img src="{{ $item->image_url }}" alt="" loading="lazy" decoding="async">
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    @include('partials.auth-error')

    @auth
        <a href="{{ route('profile') }}">Your profile</a>
    @else
        <a href="{{ route('login') }}" data-testid="discord-login">Sign in with Discord</a>
    @endauth
</x-layouts.app>
