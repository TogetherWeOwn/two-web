<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\RecurrenceFrequency;
use App\Rules\NaiveWallTime;
use App\Rules\RealWallTime;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class EventForm
{
    /**
     * Whether either recurrence bound is filled. The DatePicker returns
     * 'Y-m-d' and the count TextInput a numeric string; either counts.
     */
    private static function hasRecurrenceBound(Get $get): bool
    {
        $count = $get('recurrence_count');
        $endsOn = $get('recurrence_ends_on');

        return ($count !== null && $count !== '') || ($endsOn !== null && $endsOn !== '');
    }

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
                DateTimePicker::make('starts_at')
                    ->label('Starts (local time)')
                    ->seconds(false)
                    ->required()
                    ->rules([new NaiveWallTime, new RealWallTime]),
                DateTimePicker::make('ends_at')
                    ->label('Ends (local time)')
                    ->seconds(false)
                    ->required()
                    ->rules([new NaiveWallTime, new RealWallTime])
                    ->after('starts_at'),

                // Instant carriers for the edit page (TOG-6805). A wall time near
                // a DST fold or gap does not name a unique instant, so the edit
                // page stores the exact UTC instant it rendered and reattaches it
                // on save when the wall text comes back untouched. Empty on the
                // create page, where there is no stored instant to preserve.
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

                // Recurrence (TOG-8399). Create page only: editing a live
                // series' rule is an EditEvent concern, and a series child is
                // one meeting — its rule lives on the parent, not here.
                // Leaving the frequency empty makes a one-off, exactly as
                // before; the fields only apply once a frequency is chosen.
                Select::make('recurrence_frequency')
                    ->label('Repeat')
                    ->options([
                        RecurrenceFrequency::Weekly->value => 'Weekly',
                    ])
                    ->placeholder('Does not repeat')
                    ->visibleOn(Operation::Create)
                    ->live()
                    // A frequency with no bound is a runaway series. The field
                    // itself is optional (empty means one-off); once it names a
                    // frequency, one of the two bounds must be filled. RecurrenceInput
                    // re-checks this server-side — a form rule is UX, not trust.
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if (($value === null || $value === '') || self::hasRecurrenceBound($get)) {
                                return;
                            }

                            $fail('Give a number of occurrences or a repeat-until date.');
                        },
                    ]),
                TextInput::make('recurrence_count')
                    ->label('Occurrences')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(52)
                    ->helperText('How many meetings in total, including this one. Max 52 — a full year of weeklies.')
                    ->visibleOn(Operation::Create),
                DatePicker::make('recurrence_ends_on')
                    ->label('Repeat until')
                    ->helperText('Last meeting on or before this date. Applies together with occurrences — whichever ends the series first wins.')
                    ->visibleOn(Operation::Create),
            ]);
    }
}
