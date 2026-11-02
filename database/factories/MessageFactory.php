<?php

namespace Database\Factories;

use App\MessageChannel;
use App\MessageRole;
use App\Models\Companion;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
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
            'role' => MessageRole::User,
            'channel' => MessageChannel::Text,
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the companion sent the message.
     */
    public function fromCompanion(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Assistant,
        ]);
    }
}
