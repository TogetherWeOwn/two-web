<?php

namespace Database\Factories;

use App\Models\FeaturedContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeaturedContent> */
class FeaturedContentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'url' => $this->faker->url(),
            'image_url' => null,
            // Unpublished by default: a test that asserts something is visible
            // must say so, the same way a moderator must.
            'is_published' => false,
            'position' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }
}
