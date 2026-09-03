<?php

namespace App\Support\MemberDataAccess;

use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private bool $unattributable = false;

    /**
     * Start collecting for this request. Called by the middleware, so the panel
     * cannot forget to do it once the middleware is on the panel's stack.
     */
    public function arm(string $resource, string $action): void
    {
        $this->armed = true;
        $this->resource = $resource;
        $this->action = $action;

        // Arming starts a request and inherits nothing. Anything collected before
        // now belongs to some earlier request, and writing it here would produce a
        // row saying a moderator viewed a member on this screen when the read
        // happened somewhere else — a false positive, which in an evidence table
        // costs the same as a miss.
        $this->subjects = [];
        $this->unattributable = false;
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
        // Checked here and not only in note(): below this line a Profile may cost
        // a query, and an ordinary page of the site must not pay for one.
        if (! $this->armed) {
            return;
        }

        $subject = $model instanceof User ? $model->getKey() : $this->ownerOf($model);

        if (is_int($subject) || (is_string($subject) && ctype_digit($subject))) {
            $this->note((int) $subject);

            return;
        }

        // Member data was read and we cannot say whose. Dropping it would serve
        // profile contents with no record — the exact failure this table exists
        // to prevent — so it is remembered and the middleware refuses the read.
        if ($model instanceof Profile) {
            $this->unattributable = true;
        }
    }

    /**
     * Whether this request read member data that could not be attributed to a
     * member. Read by the middleware after flush(), which is the last moment
     * anything can still refuse to serve the response.
     */
    public function hasUnattributableRead(): bool
    {
        return $this->unattributable;
    }

    /**
     * Which member a profile row is about.
     *
     * `Profile::query()->select('bio')` hydrates a model with no `user_id` on it,
     * and reading the property then returns null — so the read is silently
     * skipped, and what was served in that case is profile *contents* rather than
     * an identifier. This is ordinary Eloquent, which is the path the design says
     * is covered, so it cannot be left as a named gap next to the raw-SQL one.
     *
     * Resolved on the query builder rather than through the model, because an
     * Eloquent read here would fire `retrieved` and re-enter this listener.
     */
    private function ownerOf(Profile $profile): int|string|null
    {
        // The question is "is this column loaded?", not "is this value null?".
        // The model's declared types say `user_id` is always an int, and on a
        // fully hydrated row that is true — a partial select just leaves the
        // attribute absent, and reading the property then returns null and looks
        // exactly like a profile belonging to nobody.
        $loaded = $profile->getAttributes();
        $owner = $loaded['user_id'] ?? null;

        if (is_int($owner) || is_string($owner)) {
            return $owner;
        }

        $key = $loaded[$profile->getKeyName()] ?? null;

        if ($key === null) {
            return null;
        }

        $resolved = DB::table($profile->getTable())
            ->where($profile->getKeyName(), $key)
            ->value('user_id');

        return is_int($resolved) || is_string($resolved) ? $resolved : null;
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

        // Whatever happens below — a row, no row, or a throw — this request is
        // done collecting, on every exit path. A recorder left armed goes on
        // collecting through every later request in the same container, and the
        // next armed request writes those subjects as its own. See arm().
        $this->armed = false;

        $viewer = $request->user();

        // Reading your own record through the panel is not a moderator looking at
        // a member, and the authenticated user gets hydrated on every request.
        // Without this every page view would log the viewer looking at themselves
        // and the signal would be buried in it.
        $subjects = array_keys($this->subjects);
        $this->subjects = [];

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

        return true;
    }
}
