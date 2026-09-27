<?php

use App\Filament\Resources\FeaturedContents\Pages\CreateFeaturedContent;
use App\Models\FeaturedContent;
use App\Models\User;

use function Pest\Livewire\livewire;

// The moderator's publishing desk: the form must guide (help text, grouped
// sections, live preview) and guard (position is unsigned, the window is
// ordered) before anything reaches the landing page. Driven through the real
// Filament create page, the same way EventResourceServiceRoutingTest does.

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

it('shows an empty-state preview before anything is typed', function () {
    livewire(CreateFeaturedContent::class)
        ->assertSee('Nothing to preview yet');
});

it('previews the typed headline and reports the row as live', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm(['title' => 'Community night on Friday', 'is_published' => true])
        ->assertSee('Community night on Friday')
        ->assertSee('Live — visitors see this on the landing page right now.');
});

it('reports staged while unpublished and scheduled inside a future window', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm(['title' => 'Embargoed announcement'])
        ->assertSee('Staged — turn Published on');

    livewire(CreateFeaturedContent::class)
        ->fillForm([
            'title' => 'Embargoed announcement',
            'is_published' => true,
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
        ])
        ->assertSee('Scheduled — appears');
});

it('escapes markup typed into the preview', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm(['title' => '<script>alert(1)</script>'])
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
});

it('creates a published row with the acting moderator as creator', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm([
            'title' => 'Read the charter',
            'body' => 'The rules we play by.',
            'url' => 'https://example.org/charter',
            'is_published' => true,
            'position' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = FeaturedContent::query()->latest('id')->sole();

    expect($row->title)->toBe('Read the charter')
        ->and($row->is_published)->toBeTrue()
        ->and($row->created_by)->toBe(auth()->id());
});

it('rejects a negative position instead of failing at the database', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm(['title' => 'Bad order', 'position' => -1])
        ->call('create')
        ->assertHasFormErrors(['position' => 'min']);

    expect(FeaturedContent::query()->where('title', 'Bad order')->count())->toBe(0);
});

it('rejects a window that closes before it opens', function () {
    livewire(CreateFeaturedContent::class)
        ->fillForm([
            'title' => 'Backwards window',
            'starts_at' => '2026-10-02 20:00',
            'ends_at' => '2026-10-01 20:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);

    expect(FeaturedContent::query()->where('title', 'Backwards window')->count())->toBe(0);
});
