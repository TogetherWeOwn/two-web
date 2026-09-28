<?php

// Label association on the profile-edit and admin event forms (TOG-6931).
//
// A form control with no associated <label> is invisible to a screen reader
// user in exactly the way that matters: the control still renders, still
// submits, still passes every functional test — it just never says what it
// is. axe catches this, but axe runs in Dusk behind a real browser; these
// pin the same invariant statically in the Unit suite, where they run on
// every commit without Chrome:
//
//   - every <input>/<textarea>/<select> in the profile edit Blade has an id
//     with a matching <label for>, so the association survives re-renders;
//   - every visible field the admin EventForm schema declares resolves a
//     non-empty, non-hidden label (Filament renders the label element from
//     this, so an empty one here is an orphan in the panel).
//
// If one of these fails, add the label — do not weaken the assertion. The
// only exemption is Hidden fields: they render type="hidden", axe excludes
// them, and the test pins that the exemption covers exactly the UTC instant
// carriers and nothing else.

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/** The fields the admin panel really declares, read off the schema. */
function adminEventFormFields(): array
{
    // The schema needs a Livewire host of the right contract — the Create
    // page, same as ThemeCoverageTest (TOG-1008). Nothing renders here.
    return array_values(EventResource::form(new Schema(Livewire::new(CreateEvent::class)))->getFlatFields());
}

it('associates every profile-edit control with a label', function () {
    $path = resource_path('views/livewire/member-profile.blade.php');

    expect($path)->toBeReadableFile();

    $blade = (string) file_get_contents($path);

    preg_match_all('/<(input|textarea|select)\b[^>]*>/i', $blade, $tags);
    preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/i', $blade, $labels);

    $labelled = array_unique($labels[1]);
    $ids = [];
    $withoutId = [];

    foreach ($tags[0] as $tag) {
        if (preg_match('/\bid="([^"]+)"/i', $tag, $match)) {
            $ids[] = $match[1];
        } else {
            $withoutId[] = $tag;
        }
    }

    expect($withoutId)->toBe([], 'A control without an id cannot be label-associated:'."\n".implode("\n", $withoutId))
        ->and($ids)->not->toBeEmpty('No controls found — the scan, not the form, is broken.')
        ->and($ids)->toContain('bio', 'games', 'timezone');

    $orphans = array_values(array_diff(array_unique($ids), $labelled));

    expect($orphans)->toBe([], 'Controls with no matching <label for>:'."\n".implode("\n", $orphans));
});

it('labels every visible admin event field', function () {
    $fields = adminEventFormFields();

    expect($fields)->not->toBeEmpty('No fields found — the scan, not the form, is broken.');

    $names = array_map(static fn (Field $field): string => $field->getName(), $fields);

    expect($names)->toContain('title', 'game', 'description', 'starts_at', 'ends_at', 'timezone', 'location', 'capacity');

    $orphans = [];

    foreach ($fields as $field) {
        if ($field instanceof Hidden) {
            continue;
        }

        if (trim((string) $field->getLabel()) === '' || $field->isLabelHidden()) {
            $orphans[] = $field->getName();
        }
    }

    expect($orphans)->toBe([], 'EventForm fields with no visible label:'."\n".implode("\n", $orphans));
});

it('exempts only hidden UTC instant carriers from the label rule', function () {
    $hidden = [];

    foreach (adminEventFormFields() as $field) {
        if ($field instanceof Hidden) {
            $hidden[] = $field->getName();
        }
    }

    $nonCarriers = array_values(array_filter($hidden, static fn (string $name): bool => ! str_ends_with($name, '_utc')));

    expect($nonCarriers)->toBe([], 'Hidden fields that are not *_utc instant carriers:'."\n".implode("\n", $nonCarriers));
});
