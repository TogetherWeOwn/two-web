<?php

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Services\EventService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Real commits, a real queue, and cache locks outside the SQL transaction:
// Queue::fake() cannot detect an orphan lock suppressing the corrected retry.
it('preflights every locked instance before dispatch and queues the complete corrected series', function (string $cacheStore) {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $cachePath = storage_path('framework/testing/ended-draft-series-'.Str::uuid());
    config([
        'cache.default' => $cacheStore,
        'cache.stores.file.path' => $cachePath,
        'cache.stores.file.lock_path' => $cachePath,
        'queue.default' => 'database',
    ]);

    try {
        $parent = Event::factory()->draft()->create([
            'recurrence_frequency' => RecurrenceFrequency::Weekly,
            'recurrence_count' => 3,
            'recurrence_index' => 1,
        ]);
        $validChild = Event::factory()->draft()->create([
            'parent_event_id' => $parent->getKey(),
            'recurrence_index' => 2,
        ]);
        $endedChild = Event::factory()->draft()->create([
            'parent_event_id' => $parent->getKey(),
            'recurrence_index' => 3,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subSecond(),
        ]);
        $updates = [];
        Events::listen('eloquent.updated: '.Event::class, function (Event $event) use (&$updates): void {
            $updates[] = $event->event_key;
        });

        expect(fn () => app(EventService::class)->publish($parent))
            ->toThrow(ValidationException::class);

        expect($parent->fresh()->status)->toBe(EventStatus::Draft)
            ->and($validChild->fresh()->status)->toBe(EventStatus::Draft)
            ->and($endedChild->fresh()->status)->toBe(EventStatus::Draft)
            ->and(DB::table('jobs')->count())->toBe(0);

        // A nested dispatch can lose its rollback-release callback. Prove its
        // 300-second unique lock did not survive and block an immediate retry.
        $lock = Cache::lock(UniqueLock::getKey(new SyncEventToDiscord($validChild->event_key)), 300);
        expect($lock->get())->toBeTrue('rejected publication left the valid child locked');
        $lock->release();
        expect($updates)->toBe([]);

        $endedChild->update([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
        ]);
        app(EventService::class)->publish($parent);

        $jobs = DB::table('jobs')->get()->map(function (object $row): SyncEventToDiscord {
            return unserialize(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
        });

        expect($parent->fresh()->status)->toBe(EventStatus::Published)
            ->and($validChild->fresh()->status)->toBe(EventStatus::Published)
            ->and($endedChild->fresh()->status)->toBe(EventStatus::Published)
            ->and($jobs)->toHaveCount(3)
            ->and($jobs->pluck('eventKey')->sort()->values()->all())
            ->toBe(collect([$parent->event_key, $validChild->event_key, $endedChild->event_key])->sort()->values()->all());
    } finally {
        File::deleteDirectory($cachePath);
    }
})->with(['array', 'file']);
