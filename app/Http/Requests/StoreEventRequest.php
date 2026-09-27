<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\IanaTimeZone;
use App\Rules\NaiveWallTime;
use App\Rules\RealWallTime;
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
        return [
            // 100 is the bot's limit on `name` in event.upsert. Refusing it here is
            // a validation error the host can fix; letting it through makes it a
            // non-retryable `malformed` in a queued job nobody is watching.
            'title' => ['required', 'string', 'max:100'],
            'game' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Local wall time in `timezone`, not an instant. The pair is what makes
            // "8pm London" mean the same thing in July and in December.
            // NaiveWallTime: an embedded offset would silently win over
            // `timezone` (TOG-6804), so offset-bearing strings are a 422 here
            // rather than a stored wrong instant. RealWallTime: a wall time
            // inside a spring-forward gap never occurred and resolves to the
            // same instant as a different wall time (TOG-6803), so gap times
            // are a 422 naming the gap rather than a stored wrong instant.
            'starts_at' => ['required', 'date', new NaiveWallTime, new RealWallTime],
            'ends_at' => ['required', 'date', 'after:starts_at', new NaiveWallTime, new RealWallTime],
            'timezone' => ['required', 'string', new IanaTimeZone],

            'location' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
