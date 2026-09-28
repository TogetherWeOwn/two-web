<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\FoldDisambiguation;
use App\Rules\IanaTimeZone;
use App\Rules\NaiveWallTime;
use App\Rules\RealWallTime;
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
            // Same pair as StoreEventRequest: NaiveWallTime refuses embedded
            // offsets (TOG-6804); RealWallTime refuses spring-gap wall times
            // that never occurred (TOG-6803); FoldDisambiguation refuses bare
            // autumn-fold wall times that occur twice (TOG-6806). All are
            // 422s, never silent.
            'starts_at' => ['required', 'date', new NaiveWallTime, new RealWallTime, new FoldDisambiguation('starts_occurrence')],
            'starts_occurrence' => ['nullable', 'in:first,second'],
            'ends_at' => ['required', 'date', 'after:starts_at', new NaiveWallTime, new RealWallTime, new FoldDisambiguation('ends_occurrence')],
            'ends_occurrence' => ['nullable', 'in:first,second'],
            'timezone' => ['required', 'string', new IanaTimeZone],
            'location' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
