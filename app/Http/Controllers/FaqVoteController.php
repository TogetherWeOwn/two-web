<?php

namespace App\Http\Controllers;

use App\Models\FaqVote;
use App\Models\User;
use App\Support\FaqEntry;
use App\Support\FaqVoteRateLimit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Was-this-helpful votes on the static FAQ page (TOG-8863).
 *
 * `/faq` itself is a session-free, database-free leaf in routes/funnel.php and
 * stays that way: this controller lives behind separate endpoints in
 * routes/web.php (session/CSRF/throttle allowed there) and the page calls it
 * as progressive enhancement — the static page renders identically without JS.
 *
 * One vote per voter per entry; re-voting UPDATES the same row (idempotent
 * PUT, same pattern as RsvpController::update, which answers 201 the first
 * time and 200 on an update). Signed-in members are keyed by `user_id`;
 * signed-out visitors by the random first-party `faq_voter` UUID cookie in
 * `voter_key` — no PII, no IP stored anywhere (documented in the privacy FAQ
 * entry). The cookie is first-party, functional, HttpOnly, Lax.
 */
class FaqVoteController
{
    public const VOTER_COOKIE = 'faq_voter';

    /**
     * The caller's current votes plus a CSRF token, so the session-free `/faq`
     * page can bootstrap its voting UI with one same-origin fetch: the `web`
     * group sets the session/XSRF cookies on this response, and the token lets
     * the page's PUTs pass VerifyCsrfToken without ever touching the static
     * leaf. Read-only: no throttle, no cookie minting for guests here.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $votes = FaqVote::query()
                ->where('user_id', $user->getKey())
                ->pluck('helpful', 'entry');
        } else {
            $voterKey = $request->cookie(self::VOTER_COOKIE);

            $votes = is_string($voterKey) && $voterKey !== ''
                ? FaqVote::query()->where('voter_key', $voterKey)->pluck('helpful', 'entry')
                : collect();
        }

        return response()->json([
            'csrf_token' => csrf_token(),
            'votes' => $votes->map(fn ($helpful) => (bool) $helpful)->all(),
        ]);
    }

    public function update(Request $request, string $entry): JsonResponse
    {
        $faqEntry = FaqEntry::tryFrom($entry);

        abort_unless($faqEntry instanceof FaqEntry, 404);

        $validated = $request->validate([
            'helpful' => ['required', 'boolean'],
        ]);

        $helpful = (bool) $validated['helpful'];
        $user = $request->user();

        if ($user instanceof User) {
            FaqVoteRateLimit::hitForMember($user);

            $vote = $this->store(
                ['entry' => $faqEntry->value, 'user_id' => $user->getKey()],
                ['helpful' => $helpful, 'voter_key' => null],
            );

            return $this->answer($faqEntry, $vote, null);
        }

        $voterKey = $request->cookie(self::VOTER_COOKIE);

        if (! is_string($voterKey) || ! preg_match('/\A[0-9a-fA-F-]{1,64}\z/', $voterKey)) {
            $voterKey = (string) Str::uuid();
        }

        FaqVoteRateLimit::hitForGuest($voterKey);

        $vote = $this->store(
            ['entry' => $faqEntry->value, 'voter_key' => $voterKey],
            ['helpful' => $helpful, 'user_id' => null],
        );

        return $this->answer($faqEntry, $vote, $voterKey);
    }

    /**
     * updateOrCreate with a retry for the one race it can lose: two concurrent
     * first votes for the same voter+entry both passing the SELECT and only
     * one winning the unique index. The loser updates the winner instead of
     * 500ing — the vote still lands exactly once.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    private function store(array $match, array $values): FaqVote
    {
        try {
            return FaqVote::query()->updateOrCreate($match, $values);
        } catch (QueryException) {
            $vote = FaqVote::query()->where($match)->firstOrFail();
            $vote->update($values);

            return $vote->refresh();
        }
    }

    private function answer(FaqEntry $faqEntry, FaqVote $vote, ?string $voterKey): JsonResponse
    {
        $response = response()->json(
            ['entry' => $faqEntry->value, 'helpful' => $vote->helpful],
            $vote->wasRecentlyCreated ? 201 : 200,
        );

        // Minted on write only, never on read: a visit that never votes leaves
        // no identifier behind. 400 days (Laravel's "forever") so a returning
        // visitor keeps their identity across restarts; the votes themselves
        // persist in the database regardless.
        if (is_string($voterKey)) {
            $response->cookie(self::VOTER_COOKIE, $voterKey, 60 * 24 * 400, null, null, null, true, false, 'Lax');
        }

        return $response;
    }
}
