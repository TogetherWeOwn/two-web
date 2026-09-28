<?php

namespace App\Livewire;

use App\Models\Profile;
use App\Models\User;
use App\Rules\IanaTimeZone;
use App\Rules\NoControlCharacters;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\Milestone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class MemberProfile extends Component
{
    #[Locked]
    public User $member;

    public bool $statsAvailable = false;

    public ?string $statsJoinedAt = null;

    public ?string $rankKey = null;

    /** @var list<array{type: string, occurredAt: string, detail: ?string}> */
    public array $milestones = [];

    public bool $editing = false;

    public bool $saved = false;

    public bool $saveFailed = false;

    public string $bio = '';

    public string $gamesText = '';

    public string $timezone = '';

    /** @var null|callable(User, array{bio: ?string, games: list<string>, timezone: ?string}): Profile */
    public static $profileWriter = null;

    public function mount(User $member, MemberStats $stats): void
    {
        Gate::authorize('view', $member);

        $this->member = $member->loadMissing('profile');
        $this->statsAvailable = $stats->available;
        $this->statsJoinedAt = $stats->joinedAt?->toIso8601String();
        $this->rankKey = $stats->rankKey;
        $this->milestones = array_map(
            static fn (Milestone $milestone): array => [
                'type' => $milestone->type,
                'occurredAt' => $milestone->occurredAt->toIso8601String(),
                'detail' => $milestone->detail,
            ],
            $stats->milestones,
        );
        $this->fillForm();
    }

    /** @return array<string, mixed> */
    public static function validationRules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000', new NoControlCharacters],
            'gamesText' => ['nullable', 'string', 'max:1700', new NoControlCharacters],
            'timezone' => ['nullable', 'string', new IanaTimeZone],
        ];
    }

    /** @throws AuthorizationException */
    public function edit(): void
    {
        Gate::authorize('updateProfile', $this->member);

        $this->saved = false;
        $this->saveFailed = false;
        $this->editing = true;
        $this->fillForm();
        // TOG-6957: opening the form unmounts the focused trigger, dropping
        // keyboard focus to <body>. The self-dispatch fires after Livewire
        // has morphed the form in, and the view's listener moves focus to
        // the form heading.
        $this->dispatch('profile-state-changed')->self();
    }

    public function cancel(): void
    {
        Gate::authorize('updateProfile', $this->member);

        $this->resetValidation();
        $this->editing = false;
        $this->fillForm();
        // TOG-6957: closing the form unmounts the focused Cancel control.
        // Refocus the Edit profile button after the round trip.
        $this->dispatch('profile-state-changed')->self();
    }

    public function save(): void
    {
        Gate::authorize('updateProfile', $this->member);

        // TOG-6957: dispatched BEFORE validation on purpose. A failed
        // `$this->validate()` throws ValidationException, which aborts this
        // method — anything dispatched after it would never run. The dispatch
        // is stored on the request and still reaches the client with the
        // error response, so the view's listener can move focus to the alert.
        // On success the same event refocuses the saved confirmation instead;
        // the listener picks its target from the morphed DOM.
        $this->dispatch('profile-state-changed')->self();

        $validated = $this->validate(static::validationRules());

        $games = [];
        foreach (preg_split('/\R/', $validated['gamesText'] ?? '') ?: [] as $game) {
            $game = trim($game);

            if ($game !== '' && ! in_array($game, $games, true)) {
                $games[] = $game;
            }
        }

        if (count($games) > 20) {
            $this->addError('gamesText', 'Add no more than 20 games.');

            return;
        }

        foreach ($games as $game) {
            if (mb_strlen($game) > 80) {
                $this->addError('gamesText', 'Keep each game name to 80 characters or fewer.');

                return;
            }
        }

        $this->saveFailed = false;

        $attributes = [
            'bio' => trim($validated['bio'] ?? '') ?: null,
            'games' => $games,
            'timezone' => $validated['timezone'] ?: null,
        ];

        try {
            $profile = is_callable(self::$profileWriter)
                ? (self::$profileWriter)($this->member, $attributes)
                : $this->member->profile()->updateOrCreate([], $attributes);
        } catch (Throwable) {
            $this->saveFailed = true;

            return;
        }

        $this->member->setRelation('profile', $profile);
        $this->editing = false;
        $this->saved = true;
        $this->fillForm();
    }

    public function render(): View
    {
        $profile = $this->profile();

        return view('livewire.member-profile', [
            'profile' => $profile,
            'games' => $this->games($profile),
            'isOwner' => auth()->user()?->is($this->member) ?? false,
            'isNewMember' => blank($profile->bio) && $this->games($profile) === [] && blank($profile->timezone),
        ]);
    }

    private function fillForm(): void
    {
        $profile = $this->profile();

        $this->bio = $profile->bio ?? '';
        $this->gamesText = implode("\n", $this->games($profile));
        $this->timezone = $profile->timezone ?? '';
    }

    /** @return list<string> */
    private function games(Profile $profile): array
    {
        return $profile->games;
    }

    private function profile(): Profile
    {
        return $this->member->profile ?? new Profile([
            'user_id' => $this->member->getKey(),
            'games' => [],
        ]);
    }
}
