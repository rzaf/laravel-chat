<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();
            $table->string('slug', 127)->unique()->nullable();
            $table->enum('type', ['direct', 'group'])->default('direct');
            $table->boolean('is_private')->default(false);
            $table->boolean('is_readonly')->default(false); // indicate if non admin members can send messages in the group (channels)
            $table->string('description', 512)->nullable();

            $table->foreignId('creator')->constrained('users');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
