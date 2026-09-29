<?php

namespace App\Support\Events;

use App\Models\EventSearchLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what guests search for on /events and what each search found
 * (TOG-8400).
 *
 * Normalized query plus result count only: no user id, no session, no IP, no
 * raw input. Guests search, and a guest search must not mint a
 * person-shaped row. Normalization (case, whitespace, length) happens here,
 * before the write, so even an unusual query string cannot smuggle
 * identifying detail past the column list.
 *
 * Fail-open by design. This logger is called from the calendar's render
 * path, so a down table must degrade to an unrecorded search, never to a
 * broken page. A failed write is a warning in the log, not an exception in
 * the guest's face.
 */
class EventSearchLogger
{
    /**
     * Collapse a raw query to its aggregatable form. Null means "not a
     * search": blank input (spaces alone must not narrow the page and must
     * not mint a row either).
     */
    public function normalize(?string $raw): ?string
    {
        // Collapse internal runs first so "hell  divers" and "hell divers"
        // aggregate together; lowercase so casing is nobody's problem.
        $normalized = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($raw ?? '')));

        if ($normalized === '') {
            return null;
        }

        // Fit the column. Truncation merges absurdly long inputs into one
        // bucket rather than refusing them — a search that long found its
        // results (or didn't) all the same.
        return mb_substr($normalized, 0, 255);
    }

    /**
     * Write one row for a rendered search. `$resultCount` is the visible
     * results the guest actually saw (past matches only count once revealed;
     * the past list is capped at 20, so a huge tail reads as 20 — exact
     * where it matters, at zero).
     */
    public function record(?string $raw, int $resultCount): void
    {
        $normalized = $this->normalize($raw);

        if ($normalized === null) {
            return;
        }

        try {
            EventSearchLog::query()->create([
                'normalized_query' => $normalized,
                'result_count' => max(0, $resultCount),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Never the exception message: a query failure can carry the DSN.
            // The class name says which kind of failure it was.
            Log::warning('Event search unavailable for logging; serving results without recording.', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * The content-gap query: zero-result queries ordered by how often guests
     * hit them, ties alphabetical so the order is stable between renders.
     * A builder (not a collection) so the admin widget can hand it straight
     * to its table with no second copy of the aggregation.
     *
     * `max(id) as id` is the record key, not data: Filament needs a unique
     * key per row and the group has no id of its own. A source row belongs
     * to exactly one group, so each group's max id is unique.
     *
     * @return Builder<EventSearchLog>
     */
    public function topZeroResultQuery(int $limit = 10): Builder
    {
        return EventSearchLog::query()
            ->where('result_count', 0)
            ->selectRaw('max(id) as id, normalized_query, count(*) as searches, max(occurred_at) as last_searched_at')
            ->groupBy('normalized_query')
            ->orderByDesc('searches')
            ->orderBy('normalized_query')
            ->limit(max(1, $limit));
    }

    /**
     * @return Collection<int, EventSearchLog>
     */
    public function topZeroResult(int $limit = 10): Collection
    {
        return $this->topZeroResultQuery($limit)->get();
    }
}
