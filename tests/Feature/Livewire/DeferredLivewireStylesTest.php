<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;
use App\Support\Profiles\Milestone;
use Illuminate\Support\Carbon;

// TOG-7335: the deferred app layout boots the Livewire runtime on
// window.load but must still ship the loading/offline hiding rules in the
// initial HTML. `@livewireScriptConfig` flips
// `FrontendAssets::hasRenderedScripts`, which makes auto-injection skip the
// styles too — so a deferred page without an explicit `@livewireStyles`
// ships zero `<style>` tags and every `wire:loading` spinner renders visibly
// until the runtime arrives (and stays visible forever with no JS).
//
// What each test owns: the deferred test pins the exact bug (guest events
// page carries the Livewire styles block); the loading-state test pins the
// no-JS consequence (every `wire:loading` element on the page is covered by
// the hiding rule, and the page actually renders such elements so the
// assertion is not vacuous); the profile test pins the other half — the
// non-deferred profile page still gets its styles through auto-injection,
// i.e. the conditional directive did not break the normal path.

/** The Livewire hiding rule, as it appears in the minified styles block. */
function livewireLoadingRule(): string
{
    return '[wire\\:loading]';
}

it('ships Livewire styles on deferred pages', function () {
    foreach ([route('events.index'), route('events.past')] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('<!-- Livewire Styles -->', livewireLoadingRule());
    }
});

it('hides every loading state before the runtime boots', function () {
    $member = User::factory()->create();
    Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $html = (string) $this->actingAs($member)
        ->get(route('events.index'))
        ->assertOk()
        ->getContent();

    // Non-vacuous: a signed-in member on a page with an upcoming event sees
    // the RSVP control, which renders `wire:loading` spinner/label pairs.
    $loadingCount = substr_count($html, 'wire:loading');
    expect($loadingCount)->toBeGreaterThan(0, 'events page renders no wire:loading elements to hide');

    // The hiding rule ships in the same document, so a no-JS client (or any
    // client before window.load fires the deferred runtime) computes every
    // loading state to display:none instead of seeing both states at once.
    expect($html)->toContain(livewireLoadingRule());

    // No show-during-loading element opts itself back out with a visible
    // inline display value that would beat the stylesheet hiding rule. An
    // inline `display:none` cooperates with it (the RSVP button hides its
    // spinner and loading copy up front as defense-in-depth, TOG-6351) —
    // anything else (`block`, `flex`, …) would render beside the default
    // state until the runtime boots. `wire:loading.remove` elements are the
    // default states, correctly visible before boot, so only the
    // show-during-loading elements are checked — and there must be some,
    // or this assertion is vacuous.
    preg_match_all('/<[^>]*\swire:loading(?![.\w-])[^>]*>|<[^>]*\swire:loading\.\w[^>]*>/', $html, $matches);
    $showing = array_filter(
        $matches[0],
        fn (string $tag) => ! str_contains($tag, 'wire:loading.remove')
            && ! str_contains($tag, 'wire:loading.attr')
            && ! str_contains($tag, 'wire:loading.class'),
    );
    expect($showing)->not->toBeEmpty();
    foreach (array_values($showing) as $tag) {
        if (preg_match('/style="[^"]*display\s*:\s*([a-z-]+)/i', $tag, $m)) {
            expect(strtolower($m[1]))->toBe('none');
        }
    }
});

it('still ships Livewire styles on the non-deferred profile page', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['display_name' => 'River']);

    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->once()
        ->with($member->discord_id)
        ->andReturn(MemberStats::available(
            discordId: $member->discord_id,
            joinedAt: Carbon::parse('2024-03-01T12:00:00Z'),
            tenureDays: 900,
            rankKey: 'veteran',
            isCurrentMember: true,
            milestones: [
                new Milestone('joined', Carbon::parse('2024-03-01T12:00:00Z'), null),
            ],
        ));
    app()->instance(MemberStatsSource::class, $source);

    $html = (string) $this->actingAs($viewer)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<!-- Livewire Styles -->');
    expect($html)->toContain(livewireLoadingRule());
});
