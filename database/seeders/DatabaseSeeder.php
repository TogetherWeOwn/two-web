<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Enough local data to build a page against: some members, a published event
     * with RSVPs, and a draft. Local development only — never run this anywhere
     * a real member could see it.
     */
    public function run(): void
    {
        $organiser = User::factory()->has(Profile::factory())->create();

        $members = User::factory()
            ->count(7)
            ->has(Profile::factory())
            ->create();

        $published = Event::factory()->for($organiser, 'creator')->create([
            'title' => 'Friday night Helldivers',
            'status' => EventStatus::Published,
        ]);

        foreach ($members->take(5) as $member) {
            Rsvp::factory()->for($published)->for($member)->create();
        }

        Event::factory()->for($organiser, 'creator')->create([
            'title' => 'Community game night (not announced yet)',
            'status' => EventStatus::Draft,
        ]);
    }
}
