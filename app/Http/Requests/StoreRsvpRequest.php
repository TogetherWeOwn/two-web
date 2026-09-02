<?php

namespace App\Http\Requests;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRsvpRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && Gate::forUser($this->actor())->allows('create', [Rsvp::class, $event, $this->subject()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(RsvpStatus::class)],

            // Accepted, and then refused by the policy. Silently ignoring it and
            // answering for the caller instead would turn "RSVP for somebody else"
            // into "RSVP for yourself", which looks like it worked.
            'user_id' => ['sometimes', 'integer'],
        ];
    }

    /** The member being answered for — themselves, unless they said otherwise. */
    public function subject(): User
    {
        $id = $this->input('user_id');

        if (! is_numeric($id) || (int) $id === $this->actor()->getKey()) {
            return $this->actor();
        }

        return User::query()->find((int) $id) ?? new User;
    }

    public function status(): RsvpStatus
    {
        return RsvpStatus::from((string) $this->validated('status'));
    }
}
