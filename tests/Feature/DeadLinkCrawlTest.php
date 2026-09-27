<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\User;

/*
 * Repeatable dead-internal-link crawl (TOG-6767).
 *
 * Seeds: `/sitemap_index.xml` + the calendar (`/events`, `/events/past`) +
 * the join flow (`/join`, `/discord`), crawled once per role (guest, member,
 * moderator). Every rendered `href`/`src`/`srcset` and every sitemap `<loc>`
 * that points at our own host is fetched; redirects are followed; anything
 * ending 4xx/5xx (except the deliberate 410 gone-event page, TOG-6781) fails
 * this test with the URL and its referrer.
 *
 * Deliberately NOT fetched:
 * - external hosts (Discord invite/CDN, ui-avatars, moderator-entered
 *   featured URLs) — not ours to keep alive;
 * - form `action=` URLs such as logout — POST-only, a GET would 405 and prove
 *   nothing (see NotFoundTest for why a fake failure here would be noise);
 * - `/_dusk/*`, `livewire/update`, filament export/import downloads — test
 *   harness or record-scoped endpoints, not links a member can reach;
 * - static files served by nginx from `public/` (fonts, filament assets) —
 *   the test client has no static-file server, so existence on disk is the
 *   honest check (a GET through Laravel would 404 in tests AND in prod).
 * - Filament table pages (`/admin/*` below the dashboard) when ext-intl is
 *   missing: the pagination view calls `Number::format()`, which 500s without
 *   intl. CI installs intl (see ci.yml setup-php extensions) and the existing
 *   admin tests assert those pages OK there, so a 500 here proves nothing
 *   about links. The skip is recorded in the report, not silent.
 *
 * Real member avatar values are absolute Discord CDN URLs (DiscordLoginController
 * writes `$discordUser->getAvatar()`); the factory's bare-md5 avatar is
 * test-only data, so fixtures here use a CDN-shaped value and a null.
 */

function crawlExtractLinks(string $body): array
{
    $out = [];

    preg_match_all('/(?:href|src)="([^"]+)"/', $body, $m);
    foreach (($m[1] ?? []) as $raw) {
        $out[] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
    }

    // srcset="url 1x, url2 2x" — keep the URL token of each candidate.
    preg_match_all('/srcset="([^"]+)"/', $body, $ms);
    foreach (($ms[1] ?? []) as $set) {
        foreach (explode(',', html_entity_decode($set, ENT_QUOTES | ENT_HTML5)) as $candidate) {
            $token = trim(strtok(trim($candidate), " \t"));
            if ($token !== '') {
                $out[] = $token;
            }
        }
    }

    preg_match_all('/<loc>([^<]+)<\/loc>/', $body, $ml);
    foreach (($ml[1] ?? []) as $raw) {
        $out[] = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5);
    }

    return array_values(array_unique($out));
}

/** Classify a raw link. Returns null to skip, else [kind, value]. */
function crawlClassify(string $raw): ?array
{
    $raw = trim($raw);

    if ($raw === '' || str_starts_with($raw, '#')) {
        return null;
    }

    $lower = strtolower($raw);
    foreach (['data:', 'mailto:', 'tel:', 'javascript:', 'blob:'] as $scheme) {
        if (str_starts_with($lower, $scheme)) {
            return null;
        }
    }

    if (str_starts_with($raw, '//')) {
        $raw = 'http:'.$raw;
    }

    if (preg_match('#^https?://#i', $raw)) {
        $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));

        return $host === $appHost ? ['internal', $raw] : ['external', $raw];
    }

    // Root-relative or page-relative path.
    return ['internal', $raw];
}

/** Non-GET / record-scoped endpoints a member link can never usefully hit. */
function crawlSkippedEndpoint(string $path): ?string
{
    foreach ([
        '/_dusk' => 'dusk test harness',
        '/livewire/update' => 'Livewire POST endpoint',
        '/livewire/upload-file' => 'Livewire POST endpoint',
        '/livewire/preview-file' => 'record-scoped temporary file',
        '/filament/exports' => 'record-scoped download',
        '/filament/imports' => 'record-scoped download',
    ] as $prefix => $reason) {
        if (str_starts_with($path, $prefix)) {
            return $reason;
        }
    }

    return null;
}

it('crawls the main routes with no broken internal links', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    FeaturedContent::factory()->published()->create([
        'title' => 'Internal spotlight',
        'url' => route('events.page', $event),
        'image_url' => null,
    ]);
    FeaturedContent::factory()->published()->create([
        'title' => 'External spotlight',
        'url' => 'https://example.com/community-recap',
        'image_url' => null,
    ]);

    $member = User::factory()->create(['avatar' => 'https://cdn.discordapp.com/avatars/123/abc.png']);
    $noAvatar = User::factory()->create(['avatar' => null]);
    $moderator = User::factory()->moderator()->create(['avatar' => 'https://cdn.discordapp.com/avatars/456/def.png']);

    $roles = [
        'guest' => null,
        'member' => $member,
        'moderator' => $moderator,
    ];

    $seeds = [
        'guest' => [
            '/sitemap_index.xml', '/', '/events', '/events/past', '/events/past?page=2',
            '/join', '/about', '/rules', '/robots.txt', '/discord',
            route('events.page', $event), route('events.ics', $event),
        ],
        'member' => ['/profile', route('profiles.show', $noAvatar), route('events.page', $event)],
        'moderator' => ['/admin'],
    ];

    $visited = [];   // "role path?query" => true
    $rows = [];      // report rows: [role, url, status, detail, referrer]
    $breaks = [];    // [role, url, status, chain, referrer]
    $queue = [];

    foreach ($seeds as $role => $urls) {
        foreach ($urls as $url) {
            $queue[] = [$role, $url, '(seed)'];
        }
    }

    $fetches = 0;
    $maxFetches = 120;

    while ($queue !== [] && $fetches < $maxFetches) {
        [$role, $raw, $referrer] = array_shift($queue);

        $classified = crawlClassify($raw);
        if ($classified === null) {
            continue;
        }
        [$kind, $value] = $classified;

        if ($kind === 'external') {
            $rows[] = [$role, $raw, '—', 'external host, not crawled', $referrer];

            continue;
        }

        // Resolve to a path+query the test client can GET.
        if (preg_match('#^https?://#i', $value)) {
            $path = (string) parse_url($value, PHP_URL_PATH);
            $query = parse_url($value, PHP_URL_QUERY);
            $target = ($path === '' ? '/' : $path).($query ? '?'.$query : '');
        } else {
            $target = str_starts_with($value, '/') ? $value : '/'.$value;
        }

        $key = $role.' '.$target;
        if (isset($visited[$key])) {
            continue;
        }
        $visited[$key] = true;

        $pathOnly = explode('?', $target, 2)[0];

        if ($pathOnly !== '/admin' && str_starts_with($pathOnly, '/admin/') && ! extension_loaded('intl')) {
            $rows[] = [$role, $target, '—', 'skipped (filament tables need ext-intl; absent in this container, present in CI)', $referrer];

            continue;
        }

        if ($reason = crawlSkippedEndpoint($pathOnly)) {
            $rows[] = [$role, $target, '—', "skipped ({$reason})", $referrer];

            continue;
        }

        // Static file served by nginx from public/ — disk is the honest check.
        if (file_exists(public_path($pathOnly)) && is_file(public_path($pathOnly))) {
            $rows[] = [$role, $target, 'static', 'exists in public/', $referrer];

            continue;
        }

        $client = $roles[$role] ? $this->actingAs($roles[$role]) : $this;
        $chain = [];
        $current = $target;

        for ($hop = 0; $hop < 6; $hop++) {
            $response = $client->get($current);
            $status = $response->getStatusCode();
            $chain[] = "{$current} => {$status}";

            if ($response->isRedirect()) {
                $location = (string) $response->headers->get('Location');
                $next = crawlClassify(html_entity_decode($location, ENT_QUOTES | ENT_HTML5));
                if ($next === null || $next[0] === 'external') {
                    $rows[] = [$role, $target, (string) $status, 'redirects outside the app: '.$location, $referrer];

                    break;
                }
                if (preg_match('#^https?://#i', $next[1])) {
                    $p = (string) parse_url($next[1], PHP_URL_PATH);
                    $q = parse_url($next[1], PHP_URL_QUERY);
                    $current = ($p === '' ? '/' : $p).($q ? '?'.$q : '');
                } else {
                    $current = str_starts_with($next[1], '/') ? $next[1] : '/'.$next[1];
                }

                continue;
            }

            $rows[] = [$role, $target, (string) $status, implode(' -> ', $chain), $referrer];

            if ($status === 410) {
                // The deliberate gone-event page (TOG-6781), not a dead link.
                break;
            }

            if ($status >= 400) {
                $breaks[] = [$role, $target, $status, implode(' -> ', $chain), $referrer];
                break;
            }

            $contentType = (string) $response->headers->get('Content-Type');
            $body = (string) $response->getContent();
            if (str_contains($contentType, 'text/html') || str_contains($body, '<loc>')) {
                foreach (crawlExtractLinks($body) as $link) {
                    $queue[] = [$role, $link, $target];
                }
            }

            break;
        }

        $fetches++;
    }

    // Evidence report: the deliverable TOG-6767 asks for alongside the script.
    $dir = getenv('PAPERCLIP_RUN_SCRATCH_DIR') ?: storage_path('app');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $lines = [
        '# Dead-internal-link crawl report (TOG-6767)',
        '',
        'Seeded from `/sitemap_index.xml` + calendar (`/events`, `/events/past`) + join flow (`/join`, `/discord`), crawled per role.',
        'External hosts, form actions, `/_dusk`, Livewire POST endpoints and record-scoped downloads are out of scope by design (see test header).',
        'Static paths are verified against `public/` because the test client has no static-file server; nginx serves them in prod.',
        '',
        '## Result',
        '',
        $breaks === []
            ? 'CLEAN — no broken internal links found.'
            : 'BROKEN — '.count($breaks).' broken internal link(s):',
        '',
    ];
    if ($breaks !== []) {
        foreach ($breaks as [$role, $url, $status, $chain, $referrer]) {
            $lines[] = "- [{$role}] `{$url}` => **{$status}** (chain: {$chain}; referrer: `{$referrer}`)";
        }
        $lines[] = '';
    }
    $lines[] = '## Visited ('.count($rows).')';
    $lines[] = '';
    $lines[] = '| role | url | status | detail | referrer |';
    $lines[] = '| --- | --- | --- | --- | --- |';
    foreach ($rows as [$role, $url, $status, $detail, $referrer]) {
        $lines[] = '| '.$role.' | `'.$url.'` | '.$status.' | '.str_replace('|', '/', $detail).' | `'.$referrer.'` |';
    }
    $lines[] = '';
    file_put_contents($dir.'/dead-link-crawl-report.md', implode("\n", $lines));
    fwrite(STDERR, "\n[crawl] report: {$dir}/dead-link-crawl-report.md rows=".count($rows).' breaks='.count($breaks)."\n");

    $detail = array_map(
        fn ($b) => "[{$b[0]}] {$b[1]} => {$b[2]} (chain: {$b[3]}; referrer: {$b[4]})",
        $breaks
    );

    expect($breaks)->toBe([], 'broken internal links: '.implode('; ', $detail));
});
