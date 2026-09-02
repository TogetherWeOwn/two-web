<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // `event_key` is left out on purpose: the model generates it on create,
            // and a factory that supplies one would let a test pass without the
            // thing that actually has to work in production ever running.
            'title' => $this->faker->sentence(3),
            'game' => $this->faker->randomElement(['Helldivers 2', 'Valorant', 'Minecraft']),
            'description' => $this->faker->paragraph(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),

            // Not UTC. A zone that is only sometimes the same as UTC is the one that
            // catches code which quietly assumes the two are interchangeable.
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
            'capacity' => null,
            'status' => EventStatus::Published,
            'discord_event_id' => null,
            'created_by' => User::factory(),
        ];
    }

    /** An event that has not been announced to the guild yet. */
    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => EventStatus::Draft]);
    }
}
