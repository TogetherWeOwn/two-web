<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Profile> */
class ProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bio' => $this->faker->sentence(),
            'games' => $this->faker->randomElements(['Valorant', 'Helldivers 2', 'Minecraft', 'Rocket League'], 2),
            'timezone' => 'Europe/London',
        ];
    }
}
