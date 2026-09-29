<?php

namespace App\Livewire;

use App\Models\Profile;
use App\Models\User;
use App\Rules\IanaTimeZone;
use App\Rules\NoControlCharacters;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\Milestone;
use App\Support\Profiles\SaveMemberProfile;
use App\Support\SpamTrap;
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

    /**
     * Set when the save (or cancel) arrives with no signed-in member behind
     * it — the form was opened authenticated and the session died underneath
     * it (SESSION_LIFETIME). Distinct from $saveFailed on purpose: the next
     * action is to log in again, not to try once more, so the message must
     * say that. Mirrors RsvpButton::$sessionExpired (TOG-8135).
     */
    public bool $sessionExpired = false;

    public string $bio = '';

    public string $gamesText = '';

    public string $timezone = '';

    /**
     * The honeypot decoy (TOG-8715). Bound to a visually hidden input no real
     * form labels or hints at: humans never fill it, form-filling bots fill
     * every input. Deliberately unlocked — a Locked field cannot be tampered
     * with, which would make the trap untestable through the same protocol a
     * bot uses.
     */
    public string $website = '';

    /**
     * Unix timestamp of when the edit form was opened (TOG-8715). Locked, so
     * only the server sets it — a client-supplied backdate cannot bypass the
     * minimum-fill-time floor. Refreshed on every edit() because a stale
     * mount stamp would exempt a bot that idles on the closed page.
     */
    #[Locked]
    public int $formOpenedAt = 0;

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
            // The decoy must stay outside validation: rejecting a filled
            // honeypot with a form error would be the oracle TOG-8715 forbids.
            // It is read raw in save() and never persisted.
            'website' => ['nullable', 'string'],
        ];
    }

    /** @throws AuthorizationException */
    public function edit(): void
    {
        Gate::authorize('updateProfile', $this->member);

        $this->saved = false;
        $this->saveFailed = false;
        $this->sessionExpired = false;
        $this->editing = true;
        $this->website = '';
        $this->formOpenedAt = now()->getTimestamp();
        $this->fillForm();
        // TOG-6957: opening the form unmounts the focused trigger, dropping
        // keyboard focus to <body>. The self-dispatch fires after Livewire
        // has morphed the form in, and the view's listener moves focus to
        // the form heading.
        $this->dispatch('profile-state-changed')->self();
    }

    public function cancel(): void
    {
        // TOG-8137: the session check comes before the gate on purpose. A
        // signed-out caller hits the gate's 403 before this method can name
        // the expired session, so the explicit check names it first — same
        // ordering as RsvpButton (TOG-8135). The self-dispatch (TOG-6957)
        // still fires: cancelling unmounts the Cancel control, and without
        // the dispatch keyboard focus drops to <body>.
        if (! auth()->user() instanceof User) {
            $this->sessionExpired = true;
            $this->dispatch('profile-state-changed')->self();

            return;
        }

        Gate::authorize('updateProfile', $this->member);

        $this->resetValidation();
        $this->editing = false;
        $this->sessionExpired = false;
        $this->fillForm();
        // TOG-6957: closing the form unmounts the focused Cancel control.
        // Refocus the Edit profile button after the round trip.
        $this->dispatch('profile-state-changed')->self();
    }

    public function save(): void
    {
        // TOG-8137: same ordering as cancel() — name the expired session
        // before the gate can 403. The form stays open with their input
        // intact (wire:model holds it client-side); only the write is refused.
        // The self-dispatch (TOG-6957) fires here too, so the listener can
        // move focus to the expiry banner after the morph.
        if (! auth()->user() instanceof User) {
            $this->sessionExpired = true;
            $this->dispatch('profile-state-changed')->self();

            return;
        }

        Gate::authorize('updateProfile', $this->member);

        // TOG-6957: dispatched BEFORE validation on purpose. A failed
        // `$this->validate()` throws ValidationException, which aborts this
        // method — anything dispatched after it would never run. The dispatch
        // is stored on the request and still reaches the client with the
        // error response, so the view's listener can move focus to the alert.
        // On success the same event refocuses the saved confirmation instead;
        // the listener picks its target from the morphed DOM.
        $this->dispatch('profile-state-changed')->self();

        // TOG-8715: validation fires before the spam trap (TOG-9361). An
        // invalid save — fast or slow, decoy filled or not — must surface
        // field errors, never a false "Profile saved." Either trap signal on
        // a VALID save — a filled decoy or a save faster than a human manages
        // after opening the form — then ends in the exact success state a
        // real save produces: no error, no retained form, "Profile saved."
        // A distinct response would be an oracle the trap must not give, and
        // nothing attacker-shaped is logged.
        $validated = $this->validate(static::validationRules());

        if (SpamTrap::honeypotFilled($this->website) || SpamTrap::tooFast($this->formOpenedAt)) {
            // Mirror the genuine path's resets: the trap must end in the
            // exact success state, including no stale failure/expired banners
            // (main's TOG-8137 flags postdate the slice). Converging the two
            // responses also keeps the trap oracle-free.
            $this->saveFailed = false;
            $this->sessionExpired = false;
            $this->editing = false;
            $this->saved = true;
            $this->fillForm();

            return;
        }

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
        $this->sessionExpired = false;

        $attributes = [
            'bio' => trim($validated['bio'] ?? '') ?: null,
            'games' => $games,
            'timezone' => $validated['timezone'] ?: null,
        ];

        try {
            $profile = is_callable(self::$profileWriter)
                ? (self::$profileWriter)($this->member, $attributes)
                : app(SaveMemberProfile::class)->save($this->member, $attributes);
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
