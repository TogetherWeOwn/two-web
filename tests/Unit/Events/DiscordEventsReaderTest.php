<?php

use App\Enums\EventStatus;
use App\Support\Events\DiscordEventsReader;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

function discordReader(Connection $connection): DiscordEventsReader
{
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->with('bot')->andReturn($connection);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')->andReturnUsing(
        fn (string $key, int $ttl, Closure $read): mixed => $read(),
    );

    return new DiscordEventsReader($db, $cache);
}

function sundaySquadRow(array $overrides = []): object
{
    return (object) array_merge([
        'event_id' => '1545955994972987422',
        'name' => 'Sunday Squad',
        'starts_at' => '2026-10-04T19:00:00.000Z',
        'channel_id' => '123456789012345678',
        'description' => 'Fall Guys for about an hour.',
    ], $overrides);
}

it('maps a view row to a transient Sunday Squad event', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')
        ->once()
        ->with('select event_id, name, starts_at, channel_id, description from web_v1.upcoming_events order by starts_at')
        ->andReturn([sundaySquadRow()]);

    $events = discordReader($connection)->upcoming();

    expect($events)->toHaveCount(1);
    $event = $events[0];
    expect($event->title)->toBe('Sunday Squad')
        ->and($event->exists)->toBeFalse()
        ->and($event->event_key)->toBe('discord:1545955994972987422')
        ->and($event->discord_event_id)->toBe('1545955994972987422')
        ->and($event->status)->toBe(EventStatus::Published)
        ->and($event->going_count)->toBeNull()
        ->and($event->starts_at->toIso8601String())->toBe('2026-10-04T19:00:00+00:00')
        ->and($event->ends_at->toIso8601String())->toBe('2026-10-04T20:00:00+00:00')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->location)->toBe('Discord');
});

it('drops rows it cannot honestly place on the calendar', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('select')->once()->andReturn([
        sundaySquadRow(),
        sundaySquadRow(['event_id' => '1', 'starts_at' => 'not-a-time']),
        sundaySquadRow(['event_id' => '2', 'name' => '   ']),
        sundaySquadRow(['event_id' => '', 'name' => 'Nameless id']),
    ]);

    $events = discordReader($connection)->upcoming();

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Sunday Squad');
});

it('renders nothing, not an error, when the bot database is unreachable', function () {
    Log::spy();

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('bot')->andThrow(new RuntimeException('secret connection string'));

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldNotReceive('remember');

    expect((new DiscordEventsReader($db, $cache))->upcoming())->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Discord events unavailable; rendering the calendar without them.', [
            'exception' => RuntimeException::class,
        ]);
});
