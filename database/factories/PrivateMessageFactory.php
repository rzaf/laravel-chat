<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class PrivateMessageFactory extends Factory
{
    public function definition(): array
    {
        $chat = Chat::query()->inRandomOrder()->first() ?? Chat::factory()->create();
        $sender = $chat->members()->inRandomOrder()->first() ?? User::query()->first();

        return [
            'chat_id' => $chat->id,
            'sender_id' => $sender?->id ?? User::query()->first()?->id,
            'content' => fake()->text(rand(50, 250)),
            'read_at' => null,
            'created_at' => fake()->dateTimeThisMonth(),
        ];
    }
}
