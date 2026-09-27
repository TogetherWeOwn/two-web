<?php

use App\Services\Bot\Announcement;
use App\Services\Bot\AnnouncementResult;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\RoleAssignment;
use App\Services\Bot\RoleAssignOutcome;
use App\Services\Bot\RoleAssignResult;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/*
 * The two actions TOG-470 added to the client (TOG-929).
 *
 * `event.upsert` is covered next door in InternalActionClientTest; everything
 * these two share with it — signing, the error envelope, retryability, the
 * not-configured refusals — is asserted there against the same private send().
 * What is asserted here is only what is different about these two actions, and
 * the difference that matters is the idempotency key: `role.assign` must send
 * no header at all and `announcement.post` must send one on every attempt.
 */

const RA_URL = 'http://127.0.0.1:3001';
const RA_ENDPOINT = 'http://127.0.0.1:3001/internal/actions';
const RA_SECRET = 'two-web-test-secret-at-least-32-characters';
const RA_KEY_ID = 'web-test';

function raClient(): InternalActionClient
{
    return new InternalActionClient(RA_URL, RA_SECRET, RA_KEY_ID, 5);
}

/** The bot's success envelope for `role.assign` (docs/INTERNAL_ACTIONS.md §3). */
function raAssigned(string $outcome = 'assigned'): array
{
    return ['ok' => true, 'result' => ['outcome' => $outcome], 'request_id' => '01JROLE0123456789'];
}

/** The bot's success envelope for `announcement.post`. */
function raPosted(string $messageId = '1122334455667788'): array
{
    return ['ok' => true, 'result' => ['outcome' => 'posted', 'message_id' => $messageId], 'request_id' => '01JANN0123456789'];
}

// -- role.assign ------------------------------------------------------------

it('sends no idempotency-key header at all for a role assign', function () {
    // §3 marks role.assign *natural*, and "no header" is the contract — an empty
    // header is a value the bot then has to interpret. This is the assertion
    // that would fail if somebody made the key a required parameter again for
    // symmetry with the other two actions.
    Http::fake([RA_ENDPOINT => Http::response(raAssigned())]);

    raClient()->assignRole(new RoleAssignment('900000000000009999', 'rocketleague'));

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Idempotency-Key'));
});

it('sends the documented role.assign payload, by key and never by role id', function () {
    Http::fake([RA_ENDPOINT => Http::response(raAssigned())]);

    raClient()->assignRole(new RoleAssignment('900000000000009999', 'rocketleague'));

    Http::assertSent(fn (Request $request) => $request->data() === [
        'action' => 'role.assign',
        'discord_id' => '900000000000009999',
        'role_key' => 'rocketleague',
    ]);
});

it('returns both role.assign outcomes as successes', function (string $wire, RoleAssignOutcome $outcome) {
    // `already_held` is a success, not a failure. It is what natural idempotency
    // looks like on the second call, and treating it as an error is how a retry
    // that worked gets reported to a member as one that did not.
    Http::fake([RA_ENDPOINT => Http::response(raAssigned($wire))]);

    $result = raClient()->assignRole(new RoleAssignment('900000000000009999', 'rocketleague'));

    expect($result)->toBeInstanceOf(RoleAssignResult::class)
        ->and($result->outcome)->toBe($outcome)
        ->and($result->requestId)->toBe('01JROLE0123456789');
})->with([
    ['assigned', RoleAssignOutcome::Assigned],
    ['already_held', RoleAssignOutcome::AlreadyHeld],
]);

it('throws a transport exception on a role.assign outcome it does not recognise', function () {
    Http::fake([RA_ENDPOINT => Http::response(raAssigned('teleported'))]);

    raClient()->assignRole(new RoleAssignment('900000000000009999', 'rocketleague'));
})->throws(BotTransportException::class);

it('rejects a discord id that is not a snowflake, before sending anything', function (string $discordId) {
    Http::fake();

    expect(fn () => new RoleAssignment($discordId, 'rocketleague'))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
})->with([
    'a role name' => ['everyone'],
    'a mention' => ['<@900000000000009999>'],
    'empty' => [''],
    'too long for 64 bits' => ['999999999999999999999'],
    'not decimal' => ['9000000000000099ab'],
]);

it('rejects a blank role key', function () {
    expect(fn () => new RoleAssignment('900000000000009999', '  '))
        ->toThrow(InvalidActionRequestException::class);
});

// -- announcement.post ------------------------------------------------------

it('sends the idempotency key it was given on an announcement', function () {
    Http::fake([RA_ENDPOINT => Http::response(raPosted())]);

    $key = InternalActionClient::newIdempotencyKey();
    raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), $key);

    Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key') === [$key]);
});

it('refuses an announcement idempotency key that is not a uuid, without calling the bot', function () {
    Http::fake();

    expect(fn () => raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), 'not-a-uuid'))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
});

it('returns the message id and the replay flag', function () {
    // §3: the message id comes back on a replay too, so a retry still tells you
    // *which* message you have — which is the only way to prove it did not post
    // a second one.
    Http::fake([RA_ENDPOINT => Http::response(raPosted('998877'), 200, ['Idempotent-Replay' => 'true'])]);

    $result = raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), InternalActionClient::newIdempotencyKey());

    expect($result)->toBeInstanceOf(AnnouncementResult::class)
        ->and($result->messageId)->toBe('998877')
        ->and($result->replayed)->toBeTrue();
});

it('reports a first post as not replayed', function () {
    Http::fake([RA_ENDPOINT => Http::response(raPosted())]);

    $result = raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), InternalActionClient::newIdempotencyKey());

    expect($result->replayed)->toBeFalse();
});

it('throws a transport exception when an announcement success carries no message id', function () {
    // Without it there is no way to tell a replay from a second post, so a
    // success we cannot store is not a success.
    Http::fake([RA_ENDPOINT => Http::response(['ok' => true, 'result' => ['outcome' => 'posted'], 'request_id' => 'x'])]);

    raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), InternalActionClient::newIdempotencyKey());
})->throws(BotTransportException::class);

it('rejects an announcement that breaks the documented limits, before sending anything', function (string $channelKey, string $body) {
    Http::fake();

    expect(fn () => new Announcement($channelKey, $body))->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
})->with([
    'no channel key' => ['', 'hello'],
    'blank channel key' => ['   ', 'hello'],
    'no body' => ['qa-throwaway', ''],
    'blank body' => ['qa-throwaway', "  \n "],
    'body over 2000 characters' => ['qa-throwaway', str_repeat('a', 2001)],
]);

it('accepts a body exactly on the 2000 character limit and counts characters not bytes', function () {
    // 2000 multi-byte characters is 4000 bytes. Counting bytes would refuse an
    // announcement Discord accepts.
    $accented = str_repeat('é', 2000);

    expect((new Announcement('qa-throwaway', $accented))->body)->toHaveLength(2000)
        ->and(mb_strlen((new Announcement('qa-throwaway', str_repeat('a', 2000)))->body))->toBe(2000);
});

it('passes an announcement body through verbatim, mentions included', function () {
    // The bot posts with `allowed_mentions: { parse: [] }`, so an @everyone
    // appears as typed and notifies nobody. Stripping or escaping it here would
    // be a second, invisible policy on top of the one that is enforced.
    Http::fake([RA_ENDPOINT => Http::response(raPosted())]);

    raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hi @everyone'), InternalActionClient::newIdempotencyKey());

    Http::assertSent(fn (Request $request) => $request->data()['body'] === 'hi @everyone');
});

it('returns a typed failure rather than throwing when the bot refuses either action', function () {
    Http::fake([RA_ENDPOINT => Http::response([
        'ok' => false,
        'error' => ['code' => 'action_not_allowed', 'message' => 'no', 'retryable' => false],
        'request_id' => '01JNO0123456789',
    ], 403)]);

    $role = raClient()->assignRole(new RoleAssignment('900000000000009999', 'rocketleague'));
    $announcement = raClient()->postAnnouncement(new Announcement('qa-throwaway', 'hello'), InternalActionClient::newIdempotencyKey());

    expect($role)->toBeInstanceOf(InternalActionFailure::class)
        ->and($role->retryable)->toBeFalse()
        ->and($announcement)->toBeInstanceOf(InternalActionFailure::class)
        ->and($announcement->code)->toBe('action_not_allowed');
});

it('never logs the announcement body', function () {
    // §4: the action, the outcome, the code and the request_id — everything
    // needed to join our line to the bot's audit row, and no more. An
    // announcement body can carry anything somebody typed into a form.
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('test', [$handler])));

    Http::fake([RA_ENDPOINT => Http::response(raPosted())]);

    raClient()->postAnnouncement(
        new Announcement('qa-throwaway', 'a very distinctive announcement body'),
        InternalActionClient::newIdempotencyKey(),
    );

    $written = collect($handler->getRecords())->map(fn ($record) => $record->formatted ?: json_encode($record->context))->implode("\n");

    expect($written)->not->toContain('a very distinctive announcement body')
        ->and($written)->not->toContain(RA_SECRET);
});
