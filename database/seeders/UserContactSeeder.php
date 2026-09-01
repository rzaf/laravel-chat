<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\User;
use App\Models\UserContacts;
use Illuminate\Database\Seeder;

class UserContactSeeder extends Seeder
{
    public const minContacts = 2;
    public const maxContacts = 6;

    public function run(): void
    {
        $userIds = User::pluck('id');
        $contacts = [];

        foreach ($userIds as $ownerId) {
            $cnt = rand(self::minContacts, self::maxContacts);
            foreach (fake()->randomElements($userIds->all(), $cnt) as $userId) {
                if ($ownerId === $userId) {
                    continue;
                }

                $contacts[] = [
                    'name' => '',
                    'owner' => $ownerId,
                    'user_id' => $userId,
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ];

                // FIX: Check if a direct chat already exists strictly between these 2 users
                $chat = Chat::query()
                    ->where('type', 'direct')
                    ->whereHas('members', fn ($q) => $q->where('users.id', $ownerId))
                    ->whereHas('members', fn ($q) => $q->where('users.id', $userId))
                    ->has('members', '=', 2) // Must only have 2 members total
                    ->first();

                if (!$chat) {
                    $chat = Chat::create([
                        'creator' => $ownerId,
                        'type' => 'direct',
                        'is_private' => true,
                        'is_readonly' => false,
                        'name' => null,
                        'slug' => null,
                    ]);

                    $chat->members()->sync([
                        $ownerId => ['role' => 'owner'],
                        $userId => ['role' => 'normal'],
                    ]);
                }
            }
        }

        $uniqueContacts = [];
        $uniqueKeys = [];
        foreach ($contacts as $contact) {
            $key = $contact['user_id'] . '-' . $contact['owner'];

            if (!in_array($key, $uniqueKeys)) {
                $uniqueContacts[] = $contact;
                $uniqueKeys[] = $key;
            }
        }

        UserContacts::insert($uniqueContacts);
    }
}