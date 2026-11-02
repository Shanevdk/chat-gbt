<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionPhoto>
 */
class CompanionPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'path' => 'companions/photos/'.fake()->uuid().'.jpg',
            'caption' => fake()->sentence(4),
        ];
    }
}
