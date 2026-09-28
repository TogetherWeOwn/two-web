<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A realistic 50-event demo calendar for staging reviewers.
 *
 * Reviewers demo the calendar against whatever rows exist, so this builds a
 * fixed, recognisable mix: 30 published upcoming events (including 4 full
 * ones), 6 drafts, 5 cancelled and 9 past, spread across six timezones.
 *
 * Idempotent on title plus the seeder's own description sentence: re-running
 * updates the same 50 rows in place (and pushes their dates back into the
 * future), never duplicates them, and never adopts a reviewer's hand-made
 * same-titled row. That also means a standing staging database stays
 * demoable — run it again whenever the upcoming events have gone stale.
 *
 * No faker, no factories: faker is a dev dependency and a staging deploy may
 * install `--no-dev`, which would make a factory-based seeder die in the only
 * place it is meant to run. Everything here is deterministic literals.
 *
 * SAFETY: refuses to run with `APP_ENV=production`. It fabricates members,
 * events and RSVPs — that is a plausible demo anywhere else and a data
 * integrity incident in production, so the guard throws rather than warns.
 */
class StagingCalendarSeeder extends Seeder
{
    // No WithoutModelEvents on purpose: Event::creating() generates the
    // immutable event_key, and updateOrCreate runs through the model — a raw
    // insert would leave event_key null and violate the NOT NULL constraint.
    // The activity-log rows this writes are legitimate staging audit data.

    public const EVENT_COUNT = 50;

    /** @var list<string> */
    private const GAMES = [
        'Helldivers 2',
        'Valorant',
        'Minecraft',
        'Rocket League',
        'Apex Legends',
        'Stardew Valley',
        'Counter-Strike 2',
        'Final Fantasy XIV',
    ];

    /** @var list<string> */
    private const FORMATS = [
        'Friday night ops',
        'Community game night',
        'Ranked grind',
        'Casual lobby',
        'Tournament practice',
        'New-player welcome',
        'Late-night session',
        'Weekend marathon',
    ];

    /** @var list<string> */
    private const TIMEZONES = [
        'Europe/London',
        'America/New_York',
        'America/Los_Angeles',
        'Australia/Sydney',
        'Asia/Tokyo',
        'UTC',
    ];

    /** @var list<string|null> */
    private const VENUES = [
        'Voice: General',
        'Voice: Squad 1',
        'Voice: Squad 2',
        null,
    ];

    /** @var list<string> */
    private const MEMBER_NAMES = [
        'Adeyemi Okafor',
        'Priya Nair',
        'Tomas Silva',
        'Mei Chen',
        'Jonas Weber',
        'Aisha Bello',
        'Liam Murphy',
        'Sofia Rossi',
        'Kenji Tanaka',
        'Amara Diallo',
        'Oscar Lindqvist',
        'Nadia Haddad',
    ];

    /**
     * Published rows that are full on purpose: capacity equals the Going count,
     * so reviewers see the "event is full" state without staging it by hand.
     *
     * @var array<int, int> event index => capacity
     */
    private const FULL_EVENTS = [
        3 => 4,
        11 => 6,
        19 => 8,
        27 => 5,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'StagingCalendarSeeder refuses to run in production: it fabricates members, events and RSVPs.'
            );
        }

        $organiser = $this->seedOrganiser();
        $members = $this->seedMembers();

        // Instants, never wall-clock readings: a wall time picked in one of
        // these zones can fall in a DST gap that never occurred, and the event
        // input rules reject those. An instant plus an IANA label has no gap.
        $now = Carbon::now();

        $usedTitles = [];
        $specs = array_merge(
            $this->upcomingSpecs($now),
            $this->draftSpecs($now),
            $this->cancelledSpecs($now),
            $this->pastSpecs($now),
        );

        foreach ($specs as $index => $spec) {
            $title = $this->uniqueTitle($usedTitles, $spec['format'], $spec['game']);
            $usedTitles[] = $title;

            // Matched on title AND the seeder's own description, not title
            // alone: a reviewer who hand-makes "Friday night ops: Helldivers 2"
            // on staging must not have their row adopted and overwritten by the
            // next seed run. A same-titled real event gets a second row rather
            // than losing its content.
            $event = Event::updateOrCreate(
                ['title' => $title, 'description' => $spec['description']],
                [
                    'game' => $spec['game'],
                    'description' => $spec['description'],
                    'starts_at' => $spec['starts_at'],
                    'ends_at' => $spec['ends_at'],
                    'timezone' => $spec['timezone'],
                    'location' => $spec['location'],
                    'capacity' => $spec['capacity'],
                    'status' => $spec['status'],
                    'discord_event_id' => null,
                    'created_by' => $organiser->getKey(),
                ],
            );

            $this->seedRsvps($event, $members, $index, $spec);
        }

        // No `$this->command->info()` summary here on purpose: `$command` is
        // only set when running under `db:seed`, and a direct `->run()` (as in
        // tests) would fatal on it. `db:seed` prints its own completion line.
    }

    private function seedOrganiser(): User
    {
        return User::updateOrCreate(
            ['discord_id' => '900000000000000001'],
            [
                'username' => 'staging-host',
                'display_name' => 'Staging Host',
                'avatar' => null,
                'is_moderator' => true,
                'discord_joined_at' => Carbon::now()->subYear(),
                'discord_synced_at' => Carbon::now(),
            ],
        );
    }

    /**
     * A plain list, not a Collection: `$members[$i % count($members)]` on a
     * non-empty list is never null, while Collection offset access always
     * reads as nullable to PHPStan.
     *
     * @return list<User>
     */
    private function seedMembers(): array
    {
        $members = [];

        foreach (self::MEMBER_NAMES as $i => $name) {
            $members[] = User::updateOrCreate(
                ['discord_id' => '90000000000000'.str_pad((string) ($i + 2), 4, '0', STR_PAD_LEFT)],
                [
                    'username' => 'staging-member-'.($i + 2),
                    'display_name' => $name,
                    'avatar' => null,
                    'is_moderator' => false,
                    'discord_joined_at' => Carbon::now()->subMonths(6),
                    'discord_synced_at' => Carbon::now(),
                ],
            );
        }

        return $members;
    }

    /**
     * Each spec is one row of the demo calendar. Thirty published events, two
     * to thirty-one days out, cycling games, formats and zones.
     *
     * @return list<array{format: string, game: string, description: string, starts_at: Carbon, ends_at: Carbon, timezone: string, location: string|null, capacity: int|null, status: EventStatus, rsvps: int}>
     */
    private function upcomingSpecs(Carbon $now): array
    {
        $specs = [];

        for ($i = 0; $i < 30; $i++) {
            $startsAt = $now->copy()->addDays(2 + $i)->addHours($i % 12);
            $specs[] = [
                'format' => self::FORMATS[$i % count(self::FORMATS)],
                'game' => self::GAMES[($i * 3) % count(self::GAMES)],
                'description' => 'Staging demo event — all members and RSVPs here are fabricated.',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours($i % 3 === 0 ? 3 : 2),
                'timezone' => self::TIMEZONES[$i % count(self::TIMEZONES)],
                'location' => self::VENUES[$i % count(self::VENUES)],
                // Full events get their exact capacity; every fourth row gets a
                // roomy cap so "spots left" renders; the rest are unlimited.
                'capacity' => self::FULL_EVENTS[$i] ?? ($i % 4 === 0 ? 10 : null),
                'status' => EventStatus::Published,
                'rsvps' => self::FULL_EVENTS[$i] ?? (($i % 5) + 1),
            ];
        }

        return $specs;
    }

    /** @return list<array{format: string, game: string, description: string, starts_at: Carbon, ends_at: Carbon, timezone: string, location: string|null, capacity: int|null, status: EventStatus, rsvps: int}> */
    private function draftSpecs(Carbon $now): array
    {
        $specs = [];

        // Never announced, so no RSVPs: there is nothing for a member to answer.
        for ($i = 0; $i < 6; $i++) {
            $startsAt = $now->copy()->addDays(4 + ($i * 5))->addHours(3);
            $specs[] = [
                'format' => 'Draft planning',
                'game' => self::GAMES[($i * 2 + 1) % count(self::GAMES)],
                'description' => 'Staging demo draft — not announced, invisible to guests.',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
                'timezone' => self::TIMEZONES[($i + 2) % count(self::TIMEZONES)],
                'location' => self::VENUES[($i + 1) % count(self::VENUES)],
                'capacity' => null,
                'status' => EventStatus::Draft,
                'rsvps' => 0,
            ];
        }

        return $specs;
    }

    /** @return list<array{format: string, game: string, description: string, starts_at: Carbon, ends_at: Carbon, timezone: string, location: string|null, capacity: int|null, status: EventStatus, rsvps: int}> */
    private function cancelledSpecs(Carbon $now): array
    {
        $specs = [];

        // Three upcoming, two already past: cancelled is a decision, not a
        // clock state, so both shapes exist. Leftover RSVPs stay, as they do
        // when a host really cancels.
        for ($i = 0; $i < 5; $i++) {
            $startsAt = $i < 3
                ? $now->copy()->addDays(6 + ($i * 7))->addHours(5)
                : $now->copy()->subDays(2 + $i)->subHours(1);
            $specs[] = [
                'format' => 'Called off',
                'game' => self::GAMES[($i * 3 + 2) % count(self::GAMES)],
                'description' => 'Staging demo cancelled event — the host called it off.',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
                'timezone' => self::TIMEZONES[($i + 4) % count(self::TIMEZONES)],
                'location' => self::VENUES[$i % count(self::VENUES)],
                'capacity' => null,
                'status' => EventStatus::Cancelled,
                'rsvps' => 2,
            ];
        }

        return $specs;
    }

    /** @return list<array{format: string, game: string, description: string, starts_at: Carbon, ends_at: Carbon, timezone: string, location: string|null, capacity: int|null, status: EventStatus, rsvps: int}> */
    private function pastSpecs(Carbon $now): array
    {
        $specs = [];

        for ($i = 0; $i < 9; $i++) {
            $startsAt = $now->copy()->subDays(1 + $i)->subHours(2);
            $specs[] = [
                'format' => 'Played',
                'game' => self::GAMES[($i * 5 + 3) % count(self::GAMES)],
                'description' => 'Staging demo past event — it ran and finished.',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
                'timezone' => self::TIMEZONES[($i + 1) % count(self::TIMEZONES)],
                'location' => self::VENUES[($i + 2) % count(self::VENUES)],
                'capacity' => null,
                'status' => EventStatus::Past,
                'rsvps' => ($i % 4) + 2,
            ];
        }

        return $specs;
    }

    /**
     * Titles are half the idempotency key, so they must be unique across the
     * 50 rows. Format × game pairs repeat, and the repeat gets a numeral —
     * deterministic, so the second run finds the same row.
     *
     * @param  list<string>  $usedTitles
     */
    private function uniqueTitle(array $usedTitles, string $format, string $game): string
    {
        $base = "{$format}: {$game}";
        $title = $base;
        $suffix = 2;

        while (in_array($title, $usedTitles, true)) {
            $title = "{$base} ({$suffix})";
            $suffix++;
        }

        return $title;
    }

    /**
     * @param  list<User>  $members
     * @param  array{rsvps: int, capacity: int|null, status: EventStatus}  $spec
     */
    private function seedRsvps(Event $event, array $members, int $index, array $spec): void
    {
        if ($spec['rsvps'] === 0) {
            return;
        }

        // Full events are all-Going by construction: the Going count must equal
        // capacity or the "full" state under test does not exist.
        $full = $spec['status'] === EventStatus::Published
            && $spec['capacity'] !== null
            && $spec['rsvps'] === $spec['capacity'];

        foreach (range(0, $spec['rsvps'] - 1) as $j) {
            $member = $members[($index * 3 + $j) % count($members)];

            $status = $full || $j % 3 !== 2
                ? RsvpStatus::Going
                : ($j % 2 === 0 ? RsvpStatus::Maybe : RsvpStatus::NotGoing);

            Rsvp::updateOrCreate(
                ['event_id' => $event->getKey(), 'user_id' => $member->getKey()],
                // Never mirrored: staging has no bot, so the mirror columns stay
                // in the "Discord does not know about this yet" state.
                ['status' => $status, 'synced_to_discord_at' => null],
            );
        }
    }
}
