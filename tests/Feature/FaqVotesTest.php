<?php

use App\Filament\Widgets\FaqHelpfulness;
use App\Http\Controllers\FaqVoteController;
use App\Models\FaqVote;
use App\Models\User;
use App\Support\FaqEntry;
use App\Support\FaqVoteRateLimit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

// TOG-8863: was-this-helpful votes on the static `/faq` page.
//
// `/faq` itself stays a session-free, database-free leaf in routes/funnel.php
// (pinned by FaqPageTest): voting is progressive enhancement behind two
// `web`-group endpoints. One vote per voter per entry; re-voting UPDATES the
// same row (idempotent PUT, same 201-first/200-update shape as
// RsvpController). Signed-in members are keyed by `user_id`; signed-out
// visitors by the random first-party `faq_voter` UUID cookie — no PII, no IP
// stored anywhere (documented in the privacy FAQ entry).

it('holds the nineteen stable entry slugs from the card', function () {
    expect(FaqEntry::values())->toBe([
        'what-is-together-we-own',
        'how-do-i-join',
        'no-invite-needed',
        'no-application-interview',
        'cant-post-what-now',
        'what-first',
        'ranks-xp-role-rewards',
        'rank-rungs',
        'level-role-rewards',
        'sunday-squad-when',
        'need-game-mic-experience',
        'find-events-guest-access',
        'how-rsvp-answers-mean',
        'game-picker-how',
        'picker-nothing-happened',
        'open-support-ticket',
        'ticket-transcript',
        'fill-profile',
        'privacy-conduct-rules',
    ]);
});

it('renders every registry entry with a hidden vote row and no backend call', function () {
    // The page bootstraps from the registry, so a slug added to FaqEntry
    // appears here with its vote row without touching the Blade file. Rows
    // stay hidden until faq-votes.js boots against the index endpoint — a
    // no-JS or backend-down visitor sees the static answers only.
    $response = $this->get('/faq')->assertOk();

    foreach (FaqEntry::values() as $slug) {
        $response->assertSee('data-faq-entry="'.$slug.'"', escape: false);
    }

    $response->assertSee('data-faq-votes="'.route('faq.votes.index').'"', escape: false);

    $content = (string) $response->getContent();

    // One vote row per entry, each carrying `hidden`: the `<p>` prompt /
    // thanks / error notes share the `data-faq-vote-` prefix, so the lookahead
    // keeps them out and only the row `<div>`s count.
    preg_match_all('/<div[^>]*data-faq-vote(?![-\w])[^>]*>/', $content, $rows);

    expect($rows[0])->toHaveCount(count(FaqEntry::values()));

    foreach ($rows[0] as $tag) {
        expect($tag)->toContain('hidden');
    }
});

it('persists a signed-in member vote', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => true])
        ->assertCreated()
        ->assertJson(['entry' => 'how-do-i-join', 'helpful' => true]);

    $vote = FaqVote::query()->sole();

    expect($vote->entry)->toBe('how-do-i-join')
        ->and($vote->helpful)->toBeTrue()
        ->and($vote->user_id)->toBe($member->id)
        ->and($vote->voter_key)->toBeNull();
});

it('updates the same row when a member re-votes instead of duplicating', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => true])
        ->assertCreated();

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => false])
        ->assertOk()
        ->assertJson(['entry' => 'how-do-i-join', 'helpful' => false]);

    expect(FaqVote::query()->count())->toBe(1)
        ->and(FaqVote::query()->sole()->helpful)->toBeFalse();
});

it('persists a guest vote and mints the faq_voter cookie', function () {
    $response = $this->putJson(route('faq.votes.update', 'what-first'), ['helpful' => true]);

    $response->assertCreated()->assertJson(['entry' => 'what-first', 'helpful' => true]);

    $cookie = $response->getCookie(FaqVoteControllerVoterCookie());

    expect($cookie)->not->toBeNull();

    $voterKey = $response->getCookie(FaqVoteControllerVoterCookie(), decrypt: true)->getValue();

    expect($voterKey)->toBeString()->not->toBe('');

    // First-party, functional, HttpOnly, Lax — the privacy FAQ entry promises
    // exactly this shape, so the test pins it rather than trusting the call.
    $raw = $response->getCookie(FaqVoteControllerVoterCookie(), decrypt: false);

    expect($raw->isHttpOnly())->toBeTrue('faq_voter must be HttpOnly')
        ->and(strtolower((string) $raw->getSameSite()))->toBe('lax', 'faq_voter must be SameSite=Lax');

    $vote = FaqVote::query()->sole();

    expect($vote->entry)->toBe('what-first')
        ->and($vote->user_id)->toBeNull()
        ->and($vote->voter_key)->toBe($voterKey);
});

it('updates the same row when a guest re-votes with their cookie', function () {
    $first = $this->putJson(route('faq.votes.update', 'what-first'), ['helpful' => true]);
    $first->assertCreated();

    $voterKey = $first->getCookie(FaqVoteControllerVoterCookie(), decrypt: true)->getValue();

    // withCredentials: the `*Json` helpers only send cookies when it is
    // chained — without it this second PUT would arrive cookieless, mint a
    // fresh identity and 201 instead of updating the same row.
    $this->withCookies([FaqVoteControllerVoterCookie() => $voterKey])->withCredentials()
        ->putJson(route('faq.votes.update', 'what-first'), ['helpful' => false])
        ->assertOk()
        ->assertJson(['entry' => 'what-first', 'helpful' => false]);

    expect(FaqVote::query()->count())->toBe(1)
        ->and(FaqVote::query()->sole()->helpful)->toBeFalse()
        ->and(FaqVote::query()->sole()->voter_key)->toBe($voterKey);
});

it('mints a fresh identity for a guest carrying a forged voter cookie', function () {
    // The cookie is attacker-settable, so it is never trusted as an identity
    // proof — but it is still namespaced: a garbage value must not collide
    // with a real voter, it gets replaced. withCredentials so the garbage is
    // actually delivered (EncryptCookies nulls the tampered value, and the
    // controller mints fresh) rather than the test passing vacuously
    // cookieless.
    $response = $this->withUnencryptedCookies([FaqVoteControllerVoterCookie() => 'not-a-real-voter'])->withCredentials()
        ->putJson(route('faq.votes.update', 'what-first'), ['helpful' => true]);

    $response->assertCreated();

    $voterKey = $response->getCookie(FaqVoteControllerVoterCookie(), decrypt: true)->getValue();

    expect($voterKey)->not->toBe('not-a-real-voter')
        ->and(FaqVote::query()->sole()->voter_key)->toBe($voterKey);
});

it('keeps a member vote and a guest vote on the same entry as separate rows', function () {
    // Guest first: `actingAs` persists on the test instance, so a "guest"
    // request after it would still be authenticated and update the member row.
    $this->putJson(route('faq.votes.update', 'what-first'), ['helpful' => false])
        ->assertCreated();

    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'what-first'), ['helpful' => true])
        ->assertCreated();

    expect(FaqVote::query()->count())->toBe(2);
});

it('rejects an unknown entry slug with a 404 and writes nothing', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'no-such-question'), ['helpful' => true])
        ->assertNotFound();

    $this->putJson(route('faq.votes.update', 'no-such-question'), ['helpful' => true])
        ->assertNotFound();

    expect(FaqVote::query()->count())->toBe(0);
});

it('validates the vote before touching the limiter, the cookie jar or the database', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $missing = $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'what-first'), []);

    $missing->assertUnprocessable();

    $garbage = $this->putJson(route('faq.votes.update', 'what-first'), ['helpful' => 'maybe']);

    $garbage->assertUnprocessable();

    expect(FaqVote::query()->count())->toBe(0);

    // A rejected write mints no identity: a visitor that never casts a valid
    // vote leaves no identifier behind.
    expect($garbage->getCookie(FaqVoteControllerVoterCookie()))->toBeNull();
});

it('hands the static page its current votes plus a CSRF token in one fetch', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)
        ->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => true])
        ->assertCreated();

    $index = $this->actingAs($member)->getJson(route('faq.votes.index'))->assertOk()->json();

    expect($index['csrf_token'])->toBeString()->not->toBe('')
        ->and($index['votes'])->toBe(['how-do-i-join' => true]);
});

it('reads back a guest’s votes from their cookie without minting on read', function () {
    $first = $this->putJson(route('faq.votes.update', 'what-first'), ['helpful' => false]);
    $voterKey = $first->getCookie(FaqVoteControllerVoterCookie(), decrypt: true)->getValue();

    $index = $this->withCookies([FaqVoteControllerVoterCookie() => $voterKey])->withCredentials()
        ->getJson(route('faq.votes.index'))->assertOk();

    expect($index->json('votes'))->toBe(['what-first' => false]);

    // Read-only: no throttle, no cookie minting for guests here.
    expect($index->getCookie(FaqVoteControllerVoterCookie()))->toBeNull();
});

it('answers a cookieless guest index with empty votes and no identifier', function () {
    $index = $this->getJson(route('faq.votes.index'))->assertOk();

    expect($index->json('votes'))->toBe([])
        ->and($index->json('csrf_token'))->toBeString()
        ->and($index->getCookie(FaqVoteControllerVoterCookie()))->toBeNull();
});

// Two tests, not one: Postgres aborts the whole transaction on the first
// expected QueryException, so a second violation in the same test fails with
// 25P02 instead of the unique violation under test.
it('keeps one member vote per entry at the database level', function () {
    $member = User::factory()->create();

    FaqVote::factory()->create([
        'entry' => 'how-do-i-join',
        'user_id' => $member->id,
        'voter_key' => null,
    ]);

    expect(fn () => FaqVote::factory()->create([
        'entry' => 'how-do-i-join',
        'user_id' => $member->id,
        'voter_key' => null,
    ]))->toThrow(QueryException::class);
});

it('keeps one guest vote per entry at the database level', function () {
    FaqVote::factory()->create(['entry' => 'what-first', 'voter_key' => 'guest-key-1']);

    expect(fn () => FaqVote::factory()->create(['entry' => 'what-first', 'voter_key' => 'guest-key-1']))
        ->toThrow(QueryException::class);
});

it('carries throttle middleware on the vote write route', function () {
    // Same structural pin as RsvpThrottleTest: the envelope only holds if the
    // `throttle:12,1` line is registered — the behaviour test below would
    // still pass at 13 hits in isolation (the controller limiter fires) but
    // the production route would be doing validation and database work on
    // every hammer hit.
    $route = app('router')->getRoutes()->getByName('faq.votes.update');

    expect($route)->not->toBeNull();

    $throttle = collect($route->gatherMiddleware())
        ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

    expect($throttle)->toBe('throttle:12,1', 'route faq.votes.update must carry throttle:12,1');
});

it('returns a 429 envelope when the member vote budget is hammered', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $entries = ['how-do-i-join', 'what-first'];

    $this->freezeTime();

    for ($attempt = 0; $attempt < FaqVoteRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->actingAs($member)
            ->putJson(route('faq.votes.update', $entries[$attempt % 2]), ['helpful' => true])
            ->assertSuccessful();
    }

    $throttled = $this->actingAs($member)
        ->putJson(route('faq.votes.update', $entries[0]), ['helpful' => true]);

    assertThrottleEnvelope($throttled, FaqVoteRateLimit::DECAY_SECONDS);

    $throttled
        ->assertHeader('X-RateLimit-Limit', (string) FaqVoteRateLimit::MAX_ATTEMPTS)
        ->assertHeader('X-RateLimit-Remaining', '0');

    // Keyed per member, never per IP: somebody else still votes.
    $otherMember = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($otherMember)
        ->putJson(route('faq.votes.update', $entries[0]), ['helpful' => true])
        ->assertSuccessful();
});

it('returns a 429 envelope when guest votes hammer the shared route', function () {
    // Each bare request carries no cookie, so every one mints a fresh guest
    // identity — the in-controller per-voter limiter never trips. The
    // route-level `throttle:12,1` still refuses the run, before validation
    // and the database run, with the same ThrottleRequestsException shape.
    $this->freezeTime();

    for ($attempt = 0; $attempt < FaqVoteRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => true])
            ->assertSuccessful();
    }

    assertThrottleEnvelope(
        $this->putJson(route('faq.votes.update', 'how-do-i-join'), ['helpful' => true]),
        FaqVoteRateLimit::DECAY_SECONDS,
    );
});

it('ranks widget scores worst-first without leaking voter halves', function () {
    Cache::flush();

    FaqVote::factory()->count(6)->create(['entry' => 'how-do-i-join', 'helpful' => false]);
    FaqVote::factory()->count(3)->create(['entry' => 'how-do-i-join', 'helpful' => true]);
    FaqVote::factory()->count(9)->create(['entry' => 'what-first', 'helpful' => true]);
    FaqVote::factory()->create(['entry' => 'what-first', 'helpful' => false]);
    FaqVote::factory()->count(10)->create(['entry' => 'fill-profile', 'helpful' => true]);
    FaqVote::factory()->count(5)->create(['entry' => 'find-events-guest-access', 'helpful' => false]);

    $scores = FaqHelpfulness::scores();

    // Worst first: find-events (0/4), how-do-i-join (3/9), what-first (9/10),
    // fill-profile (10/10). Aggregates only — the voter halves never leave
    // the table, so there is nothing member-shaped for an editor to see.
    expect(array_column($scores, 'entry'))->toBe([
        'find-events-guest-access',
        'how-do-i-join',
        'what-first',
        'fill-profile',
    ])->and(array_column($scores, 'flagged'))->toBe([true, true, true, false])
        ->and($scores[1])->toMatchArray(['helpful' => 3, 'total' => 9, 'rate' => 3 / 9]);

    foreach ($scores as $score) {
        expect(array_keys($score))->toBe(['entry', 'question', 'helpful', 'total', 'rate', 'flagged']);
    }
});

it('flags the worst entry even when nothing reaches the vote threshold', function () {
    Cache::flush();

    FaqVote::factory()->create(['entry' => 'what-first', 'helpful' => true]);

    $scores = FaqHelpfulness::scores();

    expect($scores)->toHaveCount(1)
        ->and($scores[0]['flagged'])->toBeTrue();
});

/**
 * The `faq_voter` cookie name in one place, so the tests above and the
 * controller can never drift apart silently.
 */
function FaqVoteControllerVoterCookie(): string
{
    return FaqVoteController::VOTER_COOKIE;
}
