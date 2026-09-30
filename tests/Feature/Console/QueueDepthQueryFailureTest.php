<?php

namespace Tests\Feature\Console;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use Tests\TestCase;

// No RefreshDatabase: the query failure is synthetic and must never open a socket.
final class QueueDepthQueryFailureTest extends TestCase
{
    public function test_query_failure_reports_unknown_depth_as_a_structured_cli_error(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'queue_depth_fixture',
            'queue.connections.database.table' => 'queue_jobs_fixture',
        ]);

        $failure = new QueryException(
            'queue_depth_fixture',
            'select min("created_at") as aggregate from "queue_jobs_fixture"',
            [],
            new PDOException('synthetic queue read failure'),
        );
        $database = Mockery::mock(PostgresConnection::class, [
            fn () => throw new \LogicException('The queue-depth fixture must not connect to a database.'),
            'queue_depth_fixture',
        ])->makePartial();
        // Keep the real query builder; fail only when it attempts the SQL read.
        $database->shouldReceive('select')->once()->andThrow($failure);
        DB::shouldReceive('connection')->once()->with('queue_depth_fixture')->andReturn($database);

        $exit = Artisan::call('queue:check-depth', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('error', $payload['status']);
        $this->assertArrayHasKey('pending', $payload);
        $this->assertNull($payload['pending']);
        $this->assertSame('database', $payload['connection']);
        $this->assertSame('database', $payload['driver']);
        $this->assertSame('could not read the queue table: '.$failure->getMessage(), $payload['detail']);
    }
}
