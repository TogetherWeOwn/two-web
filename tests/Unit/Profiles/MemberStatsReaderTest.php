<?php

use App\Support\Profiles\MemberStatsReader;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

it('reads one member row and all milestones without per-row queries', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with(
            'select member_id, joined_at, tenure_days, rank_key, is_current_member from web_v1.members where member_id = ? limit 1',
            ['111222333444555666'],
        )
        ->andReturn((object) [
            'member_id' => '111222333444555666',
            'joined_at' => '2024-03-01T12:00:00.000Z',
            'tenure_days' => 900,
            'rank_key' => 'veteran',
            'is_current_member' => true,
        ]);
    $connection->shouldReceive('select')
        ->once()
        ->with(
            'select milestone, occurred_at, detail from web_v1.member_milestones where member_id = ? order by occurred_at desc',
            ['111222333444555666'],
        )
        ->andReturn([
            (object) [
                'milestone' => 'rank_changed',
                'occurred_at' => '2025-04-02T18:30:00.000Z',
                'detail' => 'veteran',
            ],
            (object) [
                'milestone' => 'joined',
                'occurred_at' => '2024-03-01T12:00:00.000Z',
                'detail' => null,
            ],
        ]);

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('bot')->andReturn($connection);

    $stats = (new MemberStatsReader($db))->forMember('111222333444555666');

    expect($stats->available)->toBeTrue()
        ->and($stats->rankKey)->toBe('veteran')
        ->and($stats->milestones)->toHaveCount(2)
        ->and($stats->milestones[0]->type)->toBe('rank_changed');
});

it('does not query milestones when the member view has no row', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->once()->andReturn(null);
    $connection->shouldNotReceive('select');

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('bot')->andReturn($connection);

    $stats = (new MemberStatsReader($db))->forMember('111222333444555666');

    expect($stats->available)->toBeTrue()
        ->and($stats->milestones)->toBe([]);
});

it('returns the designed unavailable state when the bot view read fails', function () {
    Log::spy();

    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->once()->with('bot')->andThrow(new RuntimeException('secret connection string'));

    $stats = (new MemberStatsReader($db))->forMember('111222333444555666');

    expect($stats->available)->toBeFalse()
        ->and($stats->milestones)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Member stats unavailable; rendering the profile empty state.', [
            'exception' => RuntimeException::class,
        ]);
});
