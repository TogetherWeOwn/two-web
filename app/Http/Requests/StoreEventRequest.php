<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\IanaTimeZone;
use App\Support\EventInput;
use Illuminate\Support\Facades\Gate;

class StoreEventRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->actor())->allows('create', Event::class);
    }

    public function toInput(): EventInput
    {
        return EventInput::fromValidated($this->validated());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /**
     * The event field rules, shared with the agent ingress. One rule set for
     * both callers: a field the human form accepts and the machine path
     * refuses (or vice versa) is a bug in one of them, and the bot's ceilings
     * behind them do not care which caller sent the bytes.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            // 100 is the bot's limit on `name` in event.upsert. Refusing it here is
            // a validation error the host can fix; letting it through makes it a
            // non-retryable `malformed` in a queued job nobody is watching.
            'title' => ['required', 'string', 'max:100'],
            'game' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Local wall time in `timezone`, not an instant. The pair is what makes
            // "8pm London" mean the same thing in July and in December.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'string', new IanaTimeZone],

            'location' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
