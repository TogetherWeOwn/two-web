<?php

namespace App\Support\MemberDataAccess;

use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Collects, for one request, which members' data was read — then writes exactly
 * one row.
 *
 * The design decision worth defending: subjects are collected from Eloquent's
 * `retrieved` event rather than declared by each screen. Declaration is the
 * obvious approach and it is the one that rots. Every new panel resource, every
 * new relation eager-loaded onto an existing one, is another place somebody has
 * to remember to call `note()` — and the failure mode of forgetting is a read
 * that happened with no record of it, which is silent and is exactly the failure
 * this table exists to prevent. Hydrating a User or a Profile is not something a
 * screen can do accidentally *and* invisibly: it goes through the model, and the
 * model fires the event.
 *
 * `note()` stays for the reads Eloquent cannot see — raw queries, and the bot's
 * read-only views (TWO-23), which are member data that never becomes a User row.
 *
 * One row per request, not one per record: a listing that shows forty members is
 * one act of looking, and forty rows would bury it.
 */
class AccessRecorder
{
    private bool $armed = false;

    private string $resource = 'member';

    private string $action = 'view';

    /** @var array<int, true> Set semantics; keys are users.id. */
    private array $subjects = [];

    /**
     * Start collecting for this request. Called by the middleware, so the panel
     * cannot forget to do it once the middleware is on the panel's stack.
     */
    public function arm(string $resource, string $action): void
    {
        $this->armed = true;
        $this->resource = $resource;
        $this->action = $action;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /**
     * A member whose data was read. Ignored entirely when not armed, so the
     * listener below costs a boolean check on every ordinary page of the site.
     */
    public function note(int $userId): void
    {
        if (! $this->armed) {
            return;
        }

        $this->subjects[$userId] = true;
    }

    /**
     * Wired to Eloquent in AppServiceProvider. A Profile is member data about its
     * owner, so it is recorded against `user_id` and not against its own key.
     */
    public function observe(User|Profile $model): void
    {
        $subject = $model instanceof User ? $model->getKey() : $model->user_id;

        if (is_int($subject) || ctype_digit((string) $subject)) {
            $this->note((int) $subject);
        }
    }

    /**
     * Write the row. Returns false when there was nothing to record — a panel
     * page that shows no member data is not an access and does not get a row.
     *
     * Throws if the write fails. That is deliberate: the caller decides whether a
     * failed write is allowed to become a served response, and the middleware
     * says no.
     */
    public function flush(Request $request): bool
    {
        if (! $this->armed) {
            return false;
        }

        $viewer = $request->user();

        // Reading your own record through the panel is not a moderator looking at
        // a member, and the authenticated user gets hydrated on every request.
        // Without this every page view would log the viewer looking at themselves
        // and the signal would be buried in it.
        $subjects = array_keys($this->subjects);

        if ($viewer instanceof User) {
            $subjects = array_values(array_filter($subjects, fn (int $id): bool => $id !== $viewer->getKey()));
        }

        if ($subjects === []) {
            return false;
        }

        sort($subjects);

        MemberDataAccessLog::query()->create([
            // Not `?->` into a null: a read with no signed-in viewer is a defect
            // we want to find in the log, not a row we quietly drop.
            'viewer_discord_id' => $viewer instanceof User ? $viewer->discord_id : 'unauthenticated',
            'viewer_user_id' => $viewer instanceof User ? $viewer->getKey() : null,
            'resource' => $this->resource,
            'action' => $this->action,
            'subject_user_ids' => $subjects,
            'subject_count' => count($subjects),
            // The route name and never the URL. A URL carries the query string,
            // and a moderator searching for "dave" has put member data in it.
            'route' => $request->route()?->getName(),
            'occurred_at' => now(),
        ]);

        // A flushed recorder must not write the same subjects twice if something
        // downstream flushes again.
        $this->subjects = [];

        return true;
    }
}
