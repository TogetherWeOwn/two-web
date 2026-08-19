<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'discord_id' => (string) $this->faker->unique()->numberBetween(100000000000000000, 999999999999999999),
            'username' => $this->faker->unique()->userName(),
            'display_name' => $this->faker->name(),
            'avatar' => $this->faker->md5(),
            'discord_synced_at' => now(),
        ];
    }
}
