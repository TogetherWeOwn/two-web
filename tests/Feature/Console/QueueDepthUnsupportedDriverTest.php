<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// No RefreshDatabase: unsupported queues must be rejected without measuring depth.
final class QueueDepthUnsupportedDriverTest extends TestCase
{
    public function test_sync_driver_reports_unknown_depth_as_a_structured_cli_error(): void
    {
        config([
            'queue.default' => 'queue_depth_sync_fixture',
            'queue.connections.queue_depth_sync_fixture.driver' => 'sync',
        ]);

        // QueueHealth::measure starts by resolving a database connection.
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();

        $exit = Artisan::call('queue:check-depth', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame([
            'status' => 'error',
            'connection' => 'queue_depth_sync_fixture',
            'driver' => 'sync',
            'pending' => null,
            'detail' => "queue driver 'sync' has no countable depth; this probe covers the database queue only.",
        ], $payload);
    }
}
