<?php

namespace Database\Factories;

use App\Models\FaqVote;
use App\Support\FaqEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FaqVote> */
class FaqVoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Guest-shaped by default (random voter key, no account). Member votes
        // override both halves: ['user_id' => $user->id, 'voter_key' => null].
        return [
            'entry' => $this->faker->randomElement(FaqEntry::values()),
            'helpful' => $this->faker->boolean(),
            'user_id' => null,
            'voter_key' => $this->faker->uuid(),
        ];
    }
}
