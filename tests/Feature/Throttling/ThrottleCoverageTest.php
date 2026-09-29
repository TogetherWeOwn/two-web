<?php

use App\Http\Controllers\EventController;
use App\Services\AgentEventService;
use App\Support\AgentEventRateLimit;
use Illuminate\Support\Facades\Route;

// TOG-8709: throttle middleware exists on some routes but nothing failed when
// a new POST route shipped without it. This test walks the route collection
// and fails loudly listing every app-owned write route without one.
//
// Scope is app-owned writes: non-read methods (POST/PUT/PATCH/DELETE) whose
// action is an `App\` controller. Package and framework routes are excluded —
// Livewire's update/upload endpoints, Filament's admin logout and the local
// disk's upload route are vendor-owned and throttled (or not) upstream, not
// by this repo. The second test below pins that exclusion list, so a new
// write route pointing anywhere unexpected fails instead of slipping through.
//
// `api.agent-events` is a deliberate exception: it carries no `throttle:`
// middleware because machine callers sit behind shared egress and a per-IP
// bucket would be one budget for the whole fleet. Its two-level per-grant
// limiter lives in AgentEventRateLimit and throws the same 429 envelope
// (TOG-6788) from inside AgentEventService — the last test asserts that
// wiring still exists, so removing the service limiter fails here too.
//
// `csp-reports` is the second deliberate exception (TOG-8403): it lives in
// routes/funnel.php's empty middleware stack so it keeps answering during an
// app-DB outage (`throttle` reads the database-backed cache store, and the
// browser fires the sink session-free). Flood control lives in
// CspReportController instead — 8 KB body cap, always-204 (no retry
// amplification), a fixed logged key set (never the raw body), and sampling
// via CSP_REPORT_SAMPLE_RATE — pinned by tests/Feature/CspReportOnlyTest.php,
// so weakening the sink fails there rather than here.

function isAppAction(string $actionName): bool
{
    // Route definitions written as `[FooController::class, 'method']` compile
    // to a leading-backslash action (`\App\...`) while file-loaded routes
    // normalize to `App\...` — trim so both spellings match.
    return str_starts_with(ltrim($actionName, '\\'), 'App\\');
}

/** @return array<int, string> */
function unthrottledAppWriteRoutes(): array
{
    $write = ['POST', 'PUT', 'PATCH', 'DELETE'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => count(array_intersect($route->methods(), $write)) > 0)
        ->filter(fn ($route) => isAppAction($route->getActionName()))
        ->reject(fn ($route) => $route->getName() === 'api.agent-events')
        ->reject(fn ($route) => $route->getName() === 'csp-reports')
        ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:')
        ))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' ('.$route->getName().')')
        ->values()->all();
}

it('carries throttle middleware on every app-owned write route', function () {
    // Guard against the filter failing open: if the App\ prefix ever stops
    // matching (namespace rename), the list below would be [] == [] and green
    // for nothing. These names must exist first.
    // TOG-8440: `profiles.update` (PATCH /members/{user}) is deleted — the
    // Livewire form is the single writer — so it leaves this guard list.
    // TOG-9270: `events.update` (PATCH /events/{event}) is deleted — no UI
    // caller exists; the Filament panel edits through EventService — so it
    // leaves this guard list too.
    foreach (['logout', 'events.store', 'events.publish', 'events.cancel', 'events.rsvp.update', 'events.rsvp.destroy'] as $name) {
        expect(Route::getRoutes()->getByName($name))
            ->not->toBeNull("route {$name} is missing — the coverage filter may be failing open");
    }

    expect(unthrottledAppWriteRoutes())->toBe([]);
});

it('fails when a write route points outside the app or the vendor exception list', function () {
    $write = ['POST', 'PUT', 'PATCH', 'DELETE'];

    // Every non-read route must be either app-owned (audited above) or one of
    // these package/framework endpoints. A new write route to a new vendor
    // controller, a route closure, or anything else fails here by name.
    $vendorWriteRoutes = [
        'POST admin/logout (filament.admin.auth.logout)',
        'POST livewire/update (default.livewire.update)',
        'POST livewire/upload-file (livewire.upload-file)',
        'PUT storage/{path} (storage.local.upload)',
    ];

    $unexpected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => count(array_intersect($route->methods(), $write)) > 0)
        ->reject(fn ($route) => isAppAction($route->getActionName()))
        ->reject(fn ($route) => $route->getName() === 'api.agent-events')
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' ('.$route->getName().')')
        ->values()->all();

    expect($unexpected)->toEqualCanonicalizing($vendorWriteRoutes);
});

it('detects a newly added unthrottled write route', function () {
    Route::middleware(['web', 'auth'])->post('test/unthrottled-write', [EventController::class, 'store'])
        ->name('test.unthrottled-write');

    expect(unthrottledAppWriteRoutes())->toContain(
        'POST test/unthrottled-write (test.unthrottled-write)'
    );
});

it('detects removing throttle from one POST route', function () {
    $route = Route::middleware(['web', 'auth', 'throttle:30,1'])->post('test/throttled-write', [EventController::class, 'store'])
        ->name('test.throttled-write');

    // Baseline: the throttled stand-in is covered.
    expect(unthrottledAppWriteRoutes())->not->toContain('POST test/throttled-write (test.throttled-write)');

    // Simulate the `throttle:` line being deleted from the route definition.
    // `setAction` on the returned instance mutates the registered route in
    // place — no name-list refresh needed because the audit iterates the
    // collection rather than looking up by name.
    $action = $route->getAction();
    $action['middleware'] = ['web', 'auth'];
    $route->setAction($action);
    // gatherMiddleware() memoizes on first call (the baseline above), and
    // setAction does not invalidate it — clear it so the audit re-resolves.
    $route->computedMiddleware = null;

    expect(unthrottledAppWriteRoutes())->toContain('POST test/throttled-write (test.throttled-write)');
});

it('keeps the machine ingress rate-limited in the service, not the middleware', function () {
    $route = Route::getRoutes()->getByName('api.agent-events');

    expect($route)->not->toBeNull();

    // The exception above is only safe because the per-grant limiter fires
    // inside the service. If AgentEventService stops calling it, this route
    // is unthrottled and the audit's exception must go, not the limiter.
    $limiter = new ReflectionClass(AgentEventRateLimit::class);

    expect($limiter->hasMethod('hitMutating'))->toBeTrue();

    $source = (string) file_get_contents((string) (new ReflectionClass(AgentEventService::class))->getFileName());

    expect($source)->toContain('AgentEventRateLimit::hitMutating');
});
