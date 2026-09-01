<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\UserContacts;
use Database\Factories\PrivateMessageFactory;
use Illuminate\Database\Seeder;

class PrivateMessageSeeder extends Seeder
{
    public const minPms = 2;
    public const maxPms = 10;

    public function run(): void
    {
        $userContacts = UserContacts::all(['owner', 'user_id']);

        foreach ($userContacts as $contact) {
            $chat = Chat::where('type', 'direct')
                ->whereHas('members', fn ($query) => $query->where('users.id', $contact->owner))
                ->whereHas('members', fn ($query) => $query->where('users.id', $contact->user_id))
                ->first();

            if (! $chat) {
                $chat = Chat::create([
                    'creator' => $contact->owner,
                    'type' => 'direct',
                    'is_private' => true,
                    'is_readonly' => false,
                    'name' => null,
                    'slug' => null,
                ]);
                $chat->members()->sync([
                    $contact->owner => ['role' => 'owner'],
                    $contact->user_id => ['role' => 'normal'],
                ]);
            }

            for ($i = rand(self::minPms, self::maxPms); $i > 0; $i--) {
                Message::create([
                    'chat_id' => $chat->id,
                    'sender_id' => fake()->boolean(60) ? $contact->owner : $contact->user_id,
                    'content' => fake()->sentence(8),
                    'read_at' => now(),
                ]);
            }
        }
    }
}
