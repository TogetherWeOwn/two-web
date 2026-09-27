<?php

namespace App\Filament\Resources\Events\Schemas;

use DateTimeZone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                DateTimePicker::make('starts_at')
                    ->label('Starts (local time)')
                    ->seconds(false)
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->label('Ends (local time)')
                    ->seconds(false)
                    ->required()
                    ->after('starts_at'),
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
