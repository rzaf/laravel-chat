<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 32); // Emoji or reaction identifier (e.g., '👍', 'heart')

            $table->unique(['message_id', 'user_id']); // one reaction on a message pre user
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
