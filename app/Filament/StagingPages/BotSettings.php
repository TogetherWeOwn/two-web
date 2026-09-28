<?php

namespace App\Filament\StagingPages;

use App\Filament\Pages\Schemas\BotSettingsForm;
use App\Jobs\CallInternalAction;
use App\Models\User;
use App\Services\Bot\BotSettingKey;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\SettingMutation;
use App\Services\Bot\SettingRead;
use App\Services\Bot\SettingSource;
use App\Services\Bot\SettingWriteResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;
use UnitEnum;

/**
 * Guild-scoped bot settings, read and written only through signed bot actions.
 *
 * No environment value is available to this page. `unset` means "fall back to
 * the environment" and is deliberately rendered without revealing that value.
 *
 * @property-read Schema $form
 */
class BotSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Bot settings';

    protected static string|UnitEnum|null $navigationGroup = 'Bot';

    protected static ?int $navigationSort = 100;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, array{source: string, value: mixed}> */
    #[Locked]
    public array $original = [];

    /** @var array<string, array{source: string, value: mixed, updated_by: string, idempotency_key: string}> */
    #[Locked]
    public array $pendingWrites = [];

    #[Locked]
    public bool $settingsLoaded = false;

    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('access-admin');
    }

    public function mount(): void
    {
        $this->loadSettings(notify: false);
    }

    public function getSubheading(): ?string
    {
        return 'Onboarding and automod overrides for the configured bot guild.';
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return BotSettingsForm::configure($schema);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->key('form-actions'),
                ]),
        ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save')
                ->keyBindings(['mod+s'])
                ->disabled(fn (): bool => ! $this->settingsLoaded),
            Action::make('reload')
                ->label('Reload from bot')
                ->action('reloadSettings')
                ->disabled(fn (): bool => $this->pendingWrites !== [])
                ->color('gray'),
        ];
    }

    public function reloadSettings(): void
    {
        if ($this->pendingWrites !== []) {
            Notification::make()
                ->title('Retry the pending save first')
                ->body('Save changes retries the exact operation with its original idempotency key. Reloading first could read the bot cache before it refreshes.')
                ->warning()
                ->send();

            return;
        }

        $this->loadSettings(notify: true);
    }

    private function loadSettings(bool $notify): void
    {
        $state = [];
        $original = [];

        foreach (BotSettingKey::cases() as $setting) {
            $state[$setting->value] = [
                'source' => SettingSource::Unset->value,
                'value' => null,
            ];
        }

        try {
            $bot = app(InternalActionClient::class);

            foreach (BotSettingKey::cases() as $setting) {
                $answer = $bot->getSetting(new SettingRead($setting->value));

                if ($answer instanceof InternalActionFailure) {
                    $this->settingsLoaded = false;
                    $this->loadError = "The bot refused settings.get with {$answer->code}. Request {$answer->requestId}.";
                    $this->form->fill(['settings' => $state]);
                    $this->notifyLoadFailure();

                    return;
                }

                $state[$setting->value] = [
                    'source' => $answer->source->value,
                    'value' => $answer->source === SettingSource::Store
                        ? $setting->toFormValue($answer->value)
                        : null,
                ];
                $original[$setting->value] = [
                    'source' => $answer->source->value,
                    'value' => $answer->value,
                ];
            }
        } catch (BotNotConfiguredException|BotTransportException $exception) {
            report($exception);

            $this->settingsLoaded = false;
            $this->loadError = 'Bot settings are unavailable. Check the signed internal-action connection.';
            $this->form->fill(['settings' => $state]);
            $this->notifyLoadFailure();

            return;
        }

        $this->original = $original;
        $this->pendingWrites = [];
        $this->settingsLoaded = true;
        $this->loadError = null;
        $this->form->fill(['settings' => $state]);

        if ($notify) {
            Notification::make()
                ->title('Bot settings reloaded')
                ->success()
                ->send();
        }
    }

    public function save(): void
    {
        if (! $this->settingsLoaded) {
            Notification::make()
                ->title('Bot settings are not loaded')
                ->body('Reload from the bot before saving.')
                ->danger()
                ->send();

            return;
        }

        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $saved = 0;
        $replayed = false;
        $bot = app(InternalActionClient::class);

        // An uncertain response is retried with the exact operation key and body.
        // Reading first is unsafe: settings.get sees the bot's polled cache, which
        // can lag a committed write by 15 seconds.
        foreach ($this->pendingWrites as $settingValue => $pending) {
            $setting = BotSettingKey::from($settingValue);
            $answer = $this->writeChange(
                setting: $setting,
                change: ['source' => $pending['source'], 'value' => $pending['value']],
                updatedBy: $pending['updated_by'],
                bot: $bot,
                savedBefore: $saved,
                idempotencyKey: $pending['idempotency_key'],
            );

            if ($answer === null) {
                return;
            }

            $saved++;
            $replayed = $replayed || $answer->replayed;
        }

        $state = $this->form->getState();
        $changes = $this->changesFrom($state);

        if ($changes === [] && $saved === 0) {
            Notification::make()
                ->title('No bot setting changes')
                ->body('The submitted values already match the stored overrides.')
                ->info()
                ->send();

            return;
        }

        foreach ($changes as $settingValue => $change) {
            $setting = BotSettingKey::from($settingValue);
            $answer = $this->writeChange(
                setting: $setting,
                change: $change,
                updatedBy: (string) $user->discord_id,
                bot: $bot,
                savedBefore: $saved,
            );

            if ($answer === null) {
                return;
            }

            $saved++;
            $replayed = $replayed || $answer->replayed;
        }

        Notification::make()
            ->title($saved === 1 ? 'Bot setting saved' : "{$saved} bot settings saved")
            ->body($replayed
                ? 'At least one safe retry was replayed from the bot idempotency store.'
                : 'Stored overrides were updated. Observe each restart marker before expecting runtime behavior to change.')
            ->success()
            ->send();
    }

    /**
     * @param  array{source: string, value: mixed}  $change
     */
    private function writeChange(
        BotSettingKey $setting,
        array $change,
        string $updatedBy,
        InternalActionClient $bot,
        int $savedBefore,
        ?string $idempotencyKey = null,
    ): ?SettingWriteResult {
        try {
            $job = new CallInternalAction(
                new SettingMutation(
                    key: $setting->value,
                    value: $change['value'],
                    updatedBy: $updatedBy,
                ),
                $idempotencyKey,
            );

            $this->pendingWrites = [
                ...$this->pendingWrites,
                $setting->value => [
                    ...$change,
                    'updated_by' => $updatedBy,
                    'idempotency_key' => (string) $job->idempotencyKey,
                ],
            ];
            $answer = $job->runInline($bot);
        } catch (BotTransportException $exception) {
            report($exception);
            $this->notifyUncertainSave($setting, $savedBefore);

            return null;
        } catch (BotNotConfiguredException|InvalidActionRequestException $exception) {
            $this->clearPendingWrite($setting);
            report($exception);

            Notification::make()
                ->title('Bot setting could not be saved')
                ->body("{$setting->label()} was not sent. Check the internal-action configuration and the admin Discord identity.")
                ->danger()
                ->send();

            return null;
        }

        if ($answer instanceof InternalActionFailure) {
            if (! $answer->retryable) {
                $this->clearPendingWrite($setting);
            }

            Notification::make()
                ->title('Bot refused a setting change')
                ->body("{$setting->label()}: {$answer->code}. Request {$answer->requestId}."
                    .($answer->retryable ? ' Save changes retries this exact operation safely.' : '')
                    .($savedBefore > 0 ? " {$savedBefore} earlier change(s) were saved." : ''))
                ->danger()
                ->send();

            return null;
        }

        if (! $answer instanceof SettingWriteResult) {
            throw new LogicException('A settings.set job returned a result for another action.');
        }

        $this->clearPendingWrite($setting);
        $this->recordSavedChange($setting, $change, $answer);

        return $answer;
    }

    private function clearPendingWrite(BotSettingKey $setting): void
    {
        $pending = $this->pendingWrites;
        unset($pending[$setting->value]);
        $this->pendingWrites = $pending;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, array{source: string, value: mixed}>
     */
    private function changesFrom(array $state): array
    {
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $changes = [];
        $errors = [];

        foreach (BotSettingKey::cases() as $setting) {
            if (! $setting->isEditable()) {
                continue;
            }

            $entry = is_array($settings[$setting->value] ?? null) ? $settings[$setting->value] : [];
            $source = is_string($entry['source'] ?? null) ? $entry['source'] : '';
            $value = $entry['value'] ?? null;
            $errorPath = "data.settings.{$setting->value}.value";

            if (! in_array($source, [SettingSource::Store->value, SettingSource::Unset->value], true)) {
                $errors["data.settings.{$setting->value}.source"] = 'Choose a setting source.';

                continue;
            }

            if ($source === SettingSource::Store->value) {
                $message = $this->validateValue($setting, $value);

                if ($message !== null) {
                    $errors[$errorPath] = $message;

                    continue;
                }

                $value = $setting->toStoredValue($value);
            } else {
                $value = null;
            }

            $current = $this->original[$setting->value] ?? ['source' => SettingSource::Unset->value, 'value' => null];
            $currentValue = $current['source'] === SettingSource::Store->value
                ? $setting->toStoredValue($setting->toFormValue($current['value']))
                : null;

            if ($source === $current['source'] && $this->valuesEqual($value, $currentValue)) {
                continue;
            }

            $changes[$setting->value] = ['source' => $source, 'value' => $value];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $changes;
    }

    private function validateValue(BotSettingKey $setting, mixed $value): ?string
    {
        if ($setting->kind() === 'boolean') {
            return in_array((string) $value, ['0', '1'], true)
                ? null
                : 'Choose On or Off.';
        }

        if ($setting->kind() === 'integer') {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer === false || $integer < (int) $setting->minimum() || $integer > (int) $setting->maximum()) {
                return "Enter a whole number between {$setting->minimum()} and {$setting->maximum()}.";
            }

            return null;
        }

        if ($setting->kind() === 'snowflake') {
            $id = trim((string) $value);

            return $id === '' || preg_match('/^\d{17,20}$/', $id) === 1
                ? null
                : 'Enter a Discord ID containing 17 to 20 digits, or leave it blank to disable this destination.';
        }

        if ($setting->kind() === 'snowflakes') {
            foreach ($setting->toStoredValue($value) as $id) {
                if (preg_match('/^\d{17,20}$/', $id) !== 1) {
                    return 'Every line must be a Discord ID containing 17 to 20 digits.';
                }
            }

            return null;
        }

        if ($setting->kind() === 'sanctions') {
            return $this->validateSanctions((string) $value);
        }

        return null;
    }

    private function validateSanctions(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $thresholds = [];

        foreach (explode(',', $value) as $specification) {
            $parts = array_map('trim', explode(':', $specification));
            $threshold = filter_var($parts[0], FILTER_VALIDATE_INT);
            $action = $parts[1] ?? '';

            if ($threshold === false || $threshold < 1 || $threshold > 100) {
                return 'Sanction thresholds must be whole numbers between 1 and 100.';
            }

            if (isset($thresholds[$threshold])) {
                return 'Sanction thresholds must be unique.';
            }

            $thresholds[$threshold] = true;

            if (in_array($action, ['delete', 'warn'], true)) {
                if (count($parts) !== 2) {
                    return 'Delete and warn sanctions use threshold:action.';
                }

                continue;
            }

            if ($action !== 'timeout' || count($parts) > 3) {
                return 'Sanction actions must be delete, warn, or timeout.';
            }

            $seconds = ($parts[2] ?? '') === '' ? 600 : filter_var($parts[2], FILTER_VALIDATE_INT);

            if ($seconds === false || $seconds < 60 || $seconds > 2419200) {
                return 'Timeout seconds must be a whole number between 60 and 2419200.';
            }
        }

        if (! isset($thresholds[1])) {
            return 'Sanctions must start at violation 1.';
        }

        return null;
    }

    /** @param array{source: string, value: mixed} $change */
    private function recordSavedChange(BotSettingKey $setting, array $change, SettingWriteResult $result): void
    {
        $this->original[$setting->value] = $change;

        // Keep the visible source in lockstep with what the bot confirmed. The
        // write response intentionally does not echo a value.
        $this->data['settings'][$setting->value]['source'] = $result->outcome->value === 'unset'
            ? SettingSource::Unset->value
            : SettingSource::Store->value;
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return serialize($left) === serialize($right);
    }

    private function notifyLoadFailure(): void
    {
        Notification::make()
            ->title('Bot settings could not be loaded')
            ->body($this->loadError)
            ->danger()
            ->send();
    }

    private function notifyUncertainSave(BotSettingKey $setting, int $saved): void
    {
        Notification::make()
            ->title('Bot setting response was uncertain')
            ->body("Use Save changes again to retry {$setting->label()} with the same idempotency key."
                .($saved > 0 ? " {$saved} earlier change(s) were saved." : ''))
            ->warning()
            ->send();
    }
}
