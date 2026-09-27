<?php

/*
 * The moderator panel's stylesheet is a curated import list, and this is what
 * stops it going stale (TOG-1008).
 *
 * `resources/css/filament/admin/theme.css` imports only the Filament component
 * CSS this panel can render, which is what took the panel stylesheet from
 * 615KB to 342KB and its LCP from 2737ms to 2535ms. The saving is real but it
 * is not self-maintaining: add a `RichEditor` to a form, or an `ImageColumn` to
 * a table, and the component ships with no CSS. Nothing else in CI notices —
 * Lighthouse scores an unstyled field *faster*, and axe still passes, because
 * unstyled is not inaccessible. The failure is silent and it is visual, which
 * is the worst combination to leave to a human noticing.
 *
 * So this test reads the schemas the panel actually declares, maps each
 * component class to the vendor CSS file that styles it, and fails when one of
 * those files is not imported. It deliberately does not hardcode today's field
 * list: the list is derived, so tomorrow's field is covered by a test written
 * today.
 *
 * The second test guards the other half. theme.css skips Filament's own
 * `index.css` manifests, and those manifests carry two `@theme inline` blocks
 * that every colour and font utility in the panel resolves against. Those
 * blocks are reproduced verbatim in theme.css, so a `composer update` that
 * changes a token would otherwise leave our copy silently behind.
 */

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\FeaturedContents\FeaturedContentResource;
use App\Filament\Resources\FeaturedContents\Pages\CreateFeaturedContent;
use App\Filament\Resources\FeaturedContents\Pages\ListFeaturedContents;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Livewire;

/** The panel stylesheet, as text. */
function themeCss(): string
{
    $path = resource_path('css/filament/admin/theme.css');

    expect($path)->toBeReadableFile();

    return (string) file_get_contents($path);
}

/**
 * The Filament component classes this panel constructs, read off the real
 * schemas rather than listed here. The table is built against its own List
 * page and the form against its Create page, because both want a Livewire host
 * of the right contract.
 *
 * @return list<class-string>
 */
function declaredComponentClasses(): array
{
    $resources = [
        [EventResource::class, ListEvents::class, CreateEvent::class],
        [FeaturedContentResource::class, ListFeaturedContents::class, CreateFeaturedContent::class],
    ];

    $classes = [];

    foreach ($resources as [$resource, $listPage, $createPage]) {
        $table = $resource::table(Table::make(Livewire::new($listPage)));
        $form = $resource::form(new Schema(Livewire::new($createPage)));

        // array_values on each: Filament keys these by field name, and a filter
        // is routinely named after the column it filters — merging them keyed
        // would drop one of the pair on the floor and quietly stop covering it.
        $declared = array_merge(
            array_values($table->getColumns()),
            array_values($table->getFilters()),
            array_values($table->getFlatActions()),
            array_values($form->getComponents()),
        );

        foreach ($declared as $component) {
            $classes[] = $component::class;
        }
    }

    return array_values(array_unique($classes));
}

/**
 * Which vendor stylesheet styles a given component.
 *
 * A component mapped to null has no stylesheet of its own — it renders inside
 * chrome theme.css already imports wholesale. A component *missing* from this
 * map, though, is one nobody has thought about, which is exactly the bug this
 * test exists to catch, so an unrecognised class is a failure and not a pass.
 *
 * @return array<class-string, string|null>
 */
function componentStylesheetMap(): array
{
    return [
        // Form fields: one file each under filament/forms.
        DateTimePicker::class => 'forms/resources/css/components/date-time-picker.css',
        // Hidden renders a bare <input type="hidden"> with no stylesheet of
        // its own (TOG-6805 carriers).
        Hidden::class => null,
        Select::class => 'forms/resources/css/components/select.css',
        TextInput::class => 'forms/resources/css/components/text-input.css',
        Textarea::class => 'forms/resources/css/components/textarea.css',
        // Toggle's own rules live in support, next to the other input primitives.
        Toggle::class => 'support/resources/css/components/toggle.css',

        // Table columns.
        IconColumn::class => 'tables/resources/css/columns/icon.css',
        TextColumn::class => 'tables/resources/css/columns/text.css',

        // Row actions, including their confirmation modals.
        Action::class => 'actions/resources/css/actions.css',
        DeleteAction::class => 'actions/resources/css/actions.css',
        EditAction::class => 'actions/resources/css/actions.css',

        // Layout. Section's own rules are imported; Html renders its string
        // with no wrapper of its own, inside chrome theme.css already covers.
        Section::class => 'schemas/resources/css/components/section.css',
        Html::class => null,

        // Filters render inside the tables container dropdown; they have no
        // stylesheet of their own.
        SelectFilter::class => null,
        TernaryFilter::class => null,
    ];
}

it('imports a stylesheet for every component the panel declares', function () {
    $css = themeCss();
    $map = componentStylesheetMap();

    $unmapped = [];
    $missing = [];

    foreach (declaredComponentClasses() as $class) {
        if (! array_key_exists($class, $map)) {
            $unmapped[] = $class;

            continue;
        }

        $stylesheet = $map[$class];

        if ($stylesheet !== null && ! str_contains($css, $stylesheet)) {
            $missing[] = "{$class} needs vendor/filament/{$stylesheet}";
        }
    }

    expect($unmapped)->toBe([], implode("\n", [
        'The panel declares a component this test has never seen:',
        ...$unmapped,
        '',
        'resources/css/filament/admin/theme.css imports Filament component CSS',
        'explicitly, so a new component ships unstyled until its stylesheet is',
        'added there. Add the import, then add the class to',
        'componentStylesheetMap() (with null if the component has no stylesheet',
        'of its own).',
    ]));

    expect($missing)->toBe([], implode("\n", [
        'A component is declared but its stylesheet is not imported, so it',
        'renders unstyled in the panel:',
        ...$missing,
        '',
        'Add the @import to resources/css/filament/admin/theme.css.',
    ]));
});

it('keeps the vendor theme tokens it copied in step with the installed Filament', function () {
    $css = themeCss();

    // theme.css imports the component files directly and skips these two
    // manifests, so their non-@import content has to be reproduced. Compare
    // against the installed vendor files: a Filament upgrade that adds a colour
    // fails here rather than dropping it from the panel.
    $manifests = [
        base_path('vendor/filament/support/resources/css/index.css'),
        base_path('vendor/filament/filament/resources/css/index.css'),
    ];

    foreach ($manifests as $manifest) {
        expect($manifest)->toBeReadableFile();

        $source = (string) file_get_contents($manifest);
        $offset = strpos($source, '@theme inline');

        expect($offset)->not->toBeFalse("{$manifest} no longer declares an @theme block");

        $block = rtrim(substr($source, (int) $offset));

        expect(str_contains($css, $block))->toBeTrue(
            "The @theme block in {$manifest} is not reproduced verbatim in ".
            'resources/css/filament/admin/theme.css. Every colour and font '.
            'utility in the panel resolves against it, so a stale copy silently '.
            'drops shades. Copy the block across.'
        );
    }

    // The dark variant, likewise: the panel is darkMode(isForced: true), so
    // every `dark:` utility in every imported component depends on this line.
    expect($css)->toContain('@variant dark (&:where(.dark, .dark *));');
});
