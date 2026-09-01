<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatFactory extends Factory
{
    protected $model = Chat::class;

    public function definition(): array
    {
        return [
            'name' => null,
            'slug' => null,
            'type' => 'direct',
            'is_private' => true,
            'is_readonly' => false,
            'creator' => User::query()->inRandomOrder()->value('id') ?? 1,
        ];
    }
}
