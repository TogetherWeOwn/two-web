<?php

use App\Services\Bot\InternalAction;

/**
 * The allowlist and its field rules, from docs/INTERNAL_ACTIONS.md v0.3 §3.
 *
 * These duplicate checks the bot performs. That is the point: the bot would
 * answer `malformed`, but it would answer it to a queue worker minutes later,
 * where the failure is a row in `failed_jobs` nobody is reading. Rejecting at
 * construction puts the exception in the request that made the mistake.
 */
it('builds role.assign without an idempotency key', function () {
    $action = InternalAction::roleAssign('900000000000009999', 'rocketleague');

    expect($action->name)->toBe('role.assign');
    expect($action->needsIdempotencyKey())->toBeFalse();
    expect($action->payload)->toBe([
        'action' => 'role.assign',
        'discord_id' => '900000000000009999',
        'role_key' => 'rocketleague',
    ]);
});

it('rejects a role.assign discord_id that is not a snowflake', function () {
    // A role *name* here is the plausible mistake, and the bot's answer to it
    // would be an unexplained malformed.
    expect(fn () => InternalAction::roleAssign('not-a-snowflake', 'member'))
        ->toThrow(InvalidArgumentException::class, 'discord_id');

    expect(fn () => InternalAction::roleAssign('', 'member'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds announcement.post and marks it as needing a key', function () {
    $action = InternalAction::announcementPost('announcements', 'Hello server');

    expect($action->name)->toBe('announcement.post');
    expect($action->needsIdempotencyKey())->toBeTrue();
    expect($action->payload)->toBe([
        'action' => 'announcement.post',
        'channel_key' => 'announcements',
        'body' => 'Hello server',
    ]);
});

it('holds announcement bodies to Discord 2000-character ceiling, in characters not bytes', function () {
    // Exactly 2000 multi-byte characters: ~4000 bytes. A byte-counting check
    // would reject this, and it is legal.
    $atTheLimit = str_repeat('é', 2000);
    expect(InternalAction::announcementPost('announcements', $atTheLimit)->payload['body'])->toBe($atTheLimit);

    expect(fn () => InternalAction::announcementPost('announcements', str_repeat('a', 2001)))
        ->toThrow(InvalidArgumentException::class, 'body is at most 2000 characters, got 2001.');

    expect(fn () => InternalAction::announcementPost('announcements', '   '))
        ->toThrow(InvalidArgumentException::class);
});

it('builds an external event from a location', function () {
    $action = InternalAction::eventUpsert(
        eventKey: 'tog470-demo',
        name: 'Community night',
        startsAt: new DateTimeImmutable('2026-09-01T18:00:00+00:00'),
        endsAt: new DateTimeImmutable('2026-09-01T20:00:00+00:00'),
        location: 'The pub',
        description: 'Bring a friend',
    );

    expect($action->needsIdempotencyKey())->toBeTrue();
    expect($action->payload)->toBe([
        'action' => 'event.upsert',
        'event_key' => 'tog470-demo',
        'name' => 'Community night',
        'starts_at' => '2026-09-01T18:00:00.000Z',
        'ends_at' => '2026-09-01T20:00:00.000Z',
        'location' => 'The pub',
        'description' => 'Bring a friend',
    ]);
});

it('normalises event instants to UTC regardless of the timezone handed in', function () {
    // A Carbon in the app's timezone is what a real caller will pass, and the
    // bot is owed an instant, not a local wall clock.
    $action = InternalAction::eventUpsert(
        eventKey: 'tz',
        name: 'Timezone check',
        startsAt: new DateTimeImmutable('2026-09-01T19:00:00+01:00'),
        endsAt: new DateTimeImmutable('2026-09-01T21:00:00+01:00'),
        location: 'Elsewhere',
    );

    expect($action->payload['starts_at'])->toBe('2026-09-01T18:00:00.000Z');
    expect($action->payload['ends_at'])->toBe('2026-09-01T20:00:00.000Z');
});

it('builds a voice-channel event from a channel_key and omits location', function () {
    $action = InternalAction::eventUpsert(
        eventKey: 'voice',
        name: 'Voice night',
        startsAt: new DateTimeImmutable('2026-09-01T18:00:00+00:00'),
        endsAt: new DateTimeImmutable('2026-09-01T20:00:00+00:00'),
        channelKey: 'stage',
    );

    expect($action->payload)->toHaveKey('channel_key', 'stage');
    // Absent, not null: an omitted optional field and an explicit null are not
    // the same thing to a typed validator on the other side.
    expect($action->payload)->not->toHaveKey('location');
    expect($action->payload)->not->toHaveKey('description');
});

it('demands exactly one of channel_key or location', function () {
    $args = [
        'eventKey' => 'both',
        'name' => 'Ambiguous',
        'startsAt' => new DateTimeImmutable('2026-09-01T18:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-09-01T20:00:00+00:00'),
    ];

    expect(fn () => InternalAction::eventUpsert(...$args, channelKey: 'stage', location: 'The pub'))
        ->toThrow(InvalidArgumentException::class, 'both were given');

    expect(fn () => InternalAction::eventUpsert(...$args))
        ->toThrow(InvalidArgumentException::class, 'neither was given');
});

it('demands ends_at strictly after starts_at', function () {
    $at = new DateTimeImmutable('2026-09-01T18:00:00+00:00');

    expect(fn () => InternalAction::eventUpsert('e', 'n', $at, $at, location: 'x'))
        ->toThrow(InvalidArgumentException::class, 'ends_at must be after starts_at.');

    expect(fn () => InternalAction::eventUpsert('e', 'n', $at, $at->modify('-1 hour'), location: 'x'))
        ->toThrow(InvalidArgumentException::class);
});

it('holds event name and description to their documented ceilings', function () {
    $at = new DateTimeImmutable('2026-09-01T18:00:00+00:00');
    $end = new DateTimeImmutable('2026-09-01T20:00:00+00:00');

    expect(fn () => InternalAction::eventUpsert('e', str_repeat('a', 101), $at, $end, location: 'x'))
        ->toThrow(InvalidArgumentException::class, 'name is at most 100 characters');

    expect(fn () => InternalAction::eventUpsert('e', 'n', $at, $end, location: 'x', description: str_repeat('a', 1001)))
        ->toThrow(InvalidArgumentException::class, 'description is at most 1000 characters');
});

it('offers no way to build guild.add_member', function () {
    // §3: switched off pending the CEO's sign-off (TOG-57), and §5 requires it
    // to be synchronous so a live member credential never reaches the `jobs`
    // table. It must not become available by accident, so the absence is
    // asserted rather than assumed.
    // ReflectionClass::getMethods() treats its filter as an OR, so the flags are
    // applied by hand — the named constructors are the public *and* static ones.
    $constructors = array_values(array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        array_filter(
            (new ReflectionClass(InternalAction::class))->getMethods(),
            fn (ReflectionMethod $m) => $m->isPublic() && $m->isStatic(),
        ),
    ));

    expect($constructors)->toEqualCanonicalizing(['roleAssign', 'announcementPost', 'eventUpsert']);
});

it('serialises the payload to the bytes that will be signed', function () {
    $action = InternalAction::roleAssign('900000000000009999', 'rocketleague');

    expect($action->rawBody())->toBe('{"action":"role.assign","discord_id":"900000000000009999","role_key":"rocketleague"}');
});
