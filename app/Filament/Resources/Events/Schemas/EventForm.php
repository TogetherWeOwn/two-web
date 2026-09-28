<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Rules\FoldDisambiguation;
use App\Support\EventInput;
use DateTimeZone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(100),
                TextInput::make('game')
                    ->maxLength(100),
                Textarea::make('description')
                    ->rows(4)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                // Wall time plus zone, resolved to a UTC instant by EventInput
                // in the page classes — not here and not by the browser. The
                // wall time is what the host reads on a poster; the zone is the
                // other half of it.
                //
                // A wall time inside an autumn-fallback fold occurs twice
                // (TOG-6806), so each picker carries a FoldDisambiguation rule:
                // a bare fold-ambiguous wall is a field error naming the
                // occurrence select, never a silently stored maybe-wrong
                // instant. The pickers are live on blur so the selects below
                // appear the moment a fold wall is typed.
                DateTimePicker::make('starts_at')
                    ->label('Starts (local time)')
                    ->seconds(false)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('starts_occurrence', null))
                    ->rules([new FoldDisambiguation('starts_occurrence')]),
                DateTimePicker::make('ends_at')
                    ->label('Ends (local time)')
                    ->seconds(false)
                    ->required()
                    ->after('starts_at')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('ends_occurrence', null))
                    ->rules([new FoldDisambiguation('ends_occurrence')]),

                // Which side of the fold, when the wall time names two. Hidden
                // unless the corresponding wall is actually ambiguous — one
                // hour a year per zone does not earn permanent form chrome.
                // An occurrence on an unambiguous wall is accepted and ignored
                // by EventInput, so a stale value after an edit is harmless.
                Select::make('starts_occurrence')
                    ->label('Which occurrence?')
                    ->options([
                        'first' => 'First (before the clocks go back)',
                        'second' => 'Second (after the clocks go back)',
                    ])
                    ->placeholder('Pick the occurrence')
                    ->visible(fn (Get $get): bool => EventInput::isAmbiguousWallTime(
                        (string) ($get('starts_at') ?? ''),
                        (string) ($get('timezone') ?? ''),
                    ))
                    ->helperText('This local time happens twice where the clocks fall back — this picks which one.'),
                Select::make('ends_occurrence')
                    ->label('Which occurrence?')
                    ->options([
                        'first' => 'First (before the clocks go back)',
                        'second' => 'Second (after the clocks go back)',
                    ])
                    ->placeholder('Pick the occurrence')
                    ->visible(fn (Get $get): bool => EventInput::isAmbiguousWallTime(
                        (string) ($get('ends_at') ?? ''),
                        (string) ($get('timezone') ?? ''),
                    ))
                    ->helperText('This local time happens twice where the clocks fall back — this picks which one.'),

                // Instant carriers for the edit page (TOG-6805). A wall time near
                // a DST fold or gap does not name a unique instant, so the edit
                // page stores the exact UTC instant it rendered and reattaches it
                // on save when the wall text comes back untouched. Empty on the
                // create page, where there is no stored instant to preserve.
                // They sit before the zone select deliberately: the occurrence
                // selects' visibility reads the zone, and hydration evaluates
                // in component order, so the zone must already hold the stored
                // value when they ask — otherwise every fold fill misreads an
                // empty zone and hides the pick the save then demands.
                Hidden::make('starts_at_utc'),
                Hidden::make('ends_at_utc'),
                Select::make('timezone')
                    ->options(array_combine(
                        DateTimeZone::listIdentifiers(),
                        DateTimeZone::listIdentifiers(),
                    ))
                    ->default('Europe/London')
                    ->searchable()
                    ->required(),

                TextInput::make('location')
                    ->placeholder('Voice: General')
                    ->maxLength(255),
                TextInput::make('capacity')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Leave empty for unlimited. Enforced when members RSVP.'),
            ]);
    }
}
