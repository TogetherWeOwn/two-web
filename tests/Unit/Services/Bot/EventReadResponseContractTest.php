<?php

use App\Services\Bot\EventRead;
use App\Services\Bot\EventReadResult;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\InternalActionClient;
use Illuminate\Support\Facades\Http;

function eventReadContractMirror(): array
{
    return [
        'event_id' => '1122334455667788',
        'name' => 'Movie Night',
        'starts_at' => '2026-09-01T18:00:00Z',
        'location' => 'https://example.test/movie-night',
        'status' => 'scheduled',
        'observed_at' => '2026-09-01T09:00:00Z',
    ];
}

function eventReadContractClient(array $mirror): InternalActionClient
{
    Http::fake([
        'http://bot.internal:3001/internal/actions' => Http::response([
            'ok' => true,
            'result' => $mirror,
            'request_id' => '01JREADCONTRACT',
        ], 200),
    ]);
    Http::preventStrayRequests();

    return new InternalActionClient(
        'http://bot.internal:3001',
        'two-web-test-secret-at-least-32-characters',
        'web-test',
        5,
    );
}

dataset('unreadable event read mirrors', function () {
    $nonScalars = [
        'empty array' => [],
        'list' => ['unexpected'],
        'empty object' => new stdClass,
        'object' => (object) ['unexpected' => 'value'],
    ];

    foreach (['event_id', 'name', 'starts_at', 'status', 'observed_at'] as $field) {
        $mirror = eventReadContractMirror();
        unset($mirror[$field]);
        yield "{$field} missing" => [$mirror];

        foreach (['null' => null, ...$nonScalars] as $shape => $value) {
            yield "{$field} {$shape}" => [array_replace(eventReadContractMirror(), [$field => $value])];
        }
    }

    foreach ($nonScalars as $shape => $value) {
        yield "location {$shape}" => [array_replace(eventReadContractMirror(), ['location' => $value])];
    }
});

it('rejects unreadable event.read mirror fields instead of returning a partial result', function (array $mirror) {
    $client = eventReadContractClient($mirror);

    expect(fn () => $client->readEvent(new EventRead('movie-night'), InternalActionClient::newIdempotencyKey()))
        ->toThrow(BotTransportException::class, 'missing or mistyped mirror fields');

    Http::assertSentCount(1);
})->with('unreadable event read mirrors');

dataset('readable event read mirrors', function () {
    yield 'complete mirror' => [eventReadContractMirror()];
    yield 'null location' => [array_replace(eventReadContractMirror(), ['location' => null])];

    $mirror = eventReadContractMirror();
    unset($mirror['location']);
    yield 'absent location remains nullable' => [$mirror];

    // This boundary checks scalar types, not snowflake, date or lifecycle semantics.
    yield 'uninterpreted strings' => [[
        'event_id' => 'not-a-snowflake',
        'name' => 'Movie Night',
        'starts_at' => 'not-a-date',
        'location' => 'not-a-url',
        'status' => 'future-lifecycle',
        'observed_at' => 'also-not-a-date',
    ]];

    foreach (array_keys(eventReadContractMirror()) as $field) {
        foreach (['integer zero' => 0, 'float' => 1.5, 'true' => true, 'false' => false, 'empty string' => ''] as $shape => $value) {
            yield "{$field} {$shape}" => [array_replace(eventReadContractMirror(), [$field => $value])];
        }
    }
});

it('returns the complete typed event.read mirror with current scalar coercion', function (array $mirror) {
    $result = eventReadContractClient($mirror)->readEvent(
        new EventRead('movie-night'),
        InternalActionClient::newIdempotencyKey(),
    );

    expect($result)->toBeInstanceOf(EventReadResult::class)
        ->and($result->requestId)->toBe('01JREADCONTRACT')
        ->and($result->discordEventId)->toBe((string) $mirror['event_id'])
        ->and($result->name)->toBe((string) $mirror['name'])
        ->and($result->startsAt)->toBe((string) $mirror['starts_at'])
        ->and($result->location)->toBe(isset($mirror['location']) ? (string) $mirror['location'] : null)
        ->and($result->status)->toBe((string) $mirror['status'])
        ->and($result->observedAt)->toBe((string) $mirror['observed_at'])
        ->and($result->replayed)->toBeFalse();

    Http::assertSentCount(1);
})->with('readable event read mirrors');
