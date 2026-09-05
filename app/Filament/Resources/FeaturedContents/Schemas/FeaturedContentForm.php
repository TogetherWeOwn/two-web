<?php

namespace App\Filament\Resources\FeaturedContents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeaturedContentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->rows(4)
                    ->columnSpanFull(),
                TextInput::make('url')
                    ->url()
                    ->label('Link')
                    ->helperText('Where the card sends a visitor who clicks it.')
                    ->maxLength(255),
                TextInput::make('image_url')
                    ->url()
                    ->label('Image URL')
                    ->maxLength(255),
                Toggle::make('is_published')
                    ->label('Published')
                    ->helperText('Off means staged: visible here, not on the landing page.'),
                TextInput::make('position')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),

                // An optional window, for content with a natural shelf life —
                // an event announcement should not outlive the event.
                DateTimePicker::make('starts_at')
                    ->label('Show from (UTC)')
                    ->seconds(false),
                DateTimePicker::make('ends_at')
                    ->label('Show until (UTC)')
                    ->seconds(false)
                    ->after('starts_at'),
            ]);
    }
}
