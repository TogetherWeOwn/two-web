<?php

namespace Database\Factories;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataRequest> */
class DataRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'discord_id' => (string) $this->faker->unique()->numberBetween(100000000000000000, 999999999999999999),
            'type' => DataRequestType::Deletion,
            'status' => DataRequestStatus::Pending,
        ];
    }
}
