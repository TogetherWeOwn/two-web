<?php

namespace Tests\Feature\Console;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        // A real connection: real constructor, real grammar, real query
        // builder — only the SQL read itself is replaced. A Mockery partial
        // cannot do this: Mockery::mock() runs the real Connection::__construct
        // against still-mocked methods, which throws before makePartial().
        $database = new class($failure) extends PostgresConnection
        {
            public int $selectCalls = 0;

            public function __construct(private readonly QueryException $syntheticFailure)
            {
                parent::__construct(
                    fn () => throw new \LogicException('The queue-depth fixture must not connect to a database.'),
                    'queue_depth_fixture',
                );
            }

            public function select($query, $bindings = [], $useReadPdo = true)
            {
                $this->selectCalls++;

                throw $this->syntheticFailure;
            }
        };
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
        $this->assertSame(1, $database->selectCalls);
    }
}
