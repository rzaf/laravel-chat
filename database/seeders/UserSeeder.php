<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const UsersCnt = 100;
    
    public function run(): void
    {
        User::factory(self::UsersCnt)->create();

        User::factory()->create([
            'name' => 'Test User',
            'username' => 'test',
            'email' => 'test@gmail.com',
            'password' => '1234',
        ]);
    }
}
