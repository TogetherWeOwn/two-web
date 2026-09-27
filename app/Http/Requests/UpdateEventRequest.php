<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\IanaTimeZone;
use App\Rules\NaiveWallTime;
use App\Support\EventInput;
use Illuminate\Support\Facades\Gate;

class UpdateEventRequest extends AuthenticatedRequest
{
    public function toInput(): EventInput
    {
        return EventInput::fromValidated($this->validated());
    }

    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && Gate::forUser($this->actor())->allows('update', $event);
    }

    /**
     * A full replacement, not a patch. Every field is required because the times
     * and the zone are only meaningful together: accepting a new `starts_at`
     * without knowing which zone it is written in is how "8pm" ends up an hour out.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'game' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date', new NaiveWallTime],
            'ends_at' => ['required', 'date', 'after:starts_at', new NaiveWallTime],
            'timezone' => ['required', 'string', new IanaTimeZone],
            'location' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
