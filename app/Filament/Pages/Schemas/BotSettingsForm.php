<?php

namespace App\Filament\Pages\Schemas;

use App\Filament\StagingPages\BotSettings;
use App\Services\Bot\BotSettingKey;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class BotSettingsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('How values are resolved')
                ->description('A stored dashboard override wins over the bot environment. Choosing environment fallback removes the stored row; the bot never returns the environment value to this page. Capability gates and protected actor lists remain environment-only. The cold automod master switch stays read-only until its required operator restart-card workflow lands.'),
            Tabs::make('Bot setting groups')
                ->tabs([
                    self::group('Onboarding'),
                    self::group('Automod'),
                ])
                ->persistTabInQueryString(),
        ]);
    }

    private static function group(string $group): Tab
    {
        $components = [];

        foreach (BotSettingKey::cases() as $setting) {
            if ($setting->group() !== $group) {
                continue;
            }

            $components[] = Select::make("settings.{$setting->value}.source")
                ->label("{$setting->label()} source")
                ->options([
                    'unset' => 'Environment fallback',
                    'store' => 'Stored dashboard override',
                ])
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                ->disabled(fn (BotSettings $livewire): bool => ! $livewire->settingsLoaded || ! $setting->isEditable())
                ->helperText('The environment fallback value is intentionally not disclosed.');

            $components[] = self::valueField($setting)
                ->disabled(fn (Get $get, BotSettings $livewire): bool => ! $livewire->settingsLoaded
                    || ! $setting->isEditable()
                    || $get("data.settings.{$setting->value}.source", isAbsolute: true) !== 'store')
                ->helperText($setting->description().' '.$setting->restartNotice());
        }

        return Tab::make($group)
            ->schema($components)
            ->columns(2);
    }

    private static function valueField(BotSettingKey $setting): Field
    {
        $name = "settings.{$setting->value}.value";

        if ($setting->kind() === 'boolean') {
            return Select::make($name)
                ->label("{$setting->label()} value")
                ->options([
                    '1' => 'On',
                    '0' => 'Off',
                ])
                ->placeholder('Not disclosed');
        }

        if ($setting->kind() === 'integer') {
            return TextInput::make($name)
                ->label("{$setting->label()} value")
                ->numeric()
                ->minValue($setting->minimum())
                ->maxValue($setting->maximum());
        }

        if (in_array($setting->kind(), ['snowflakes', 'lines'], true)) {
            return Textarea::make($name)
                ->label("{$setting->label()} value")
                ->rows(4);
        }

        return TextInput::make($name)
            ->label("{$setting->label()} value")
            ->maxLength($setting->kind() === 'sanctions' ? 2000 : 255);
    }
}
