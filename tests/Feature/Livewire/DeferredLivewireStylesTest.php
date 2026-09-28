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

    // No loading element opts itself back out with an inline display style
    // that would beat the stylesheet hiding rule.
    preg_match_all('/<[^>]*\swire:loading[^>]*>/', $html, $matches);
    foreach ($matches[0] as $tag) {
        expect($tag)->not->toMatch('/style="[^"]*display/i', "loading element overrides the hiding rule: {$tag}");
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
