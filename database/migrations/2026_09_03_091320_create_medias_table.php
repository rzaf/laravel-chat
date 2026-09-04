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
        Schema::create('medias', function (Blueprint $table) {
            $table->id();

            // Polymorphic relation (model_type, model_id)
            $table->morphs('model');

            // Storage metadata
            $table->string('disk')->default('local'); // e.g., 'public', 's3', 'private'
            $table->string('path');                   // Relative path: avatars/12/uuid.png
            $table->string('file_name');              // Original filename: my-photo.jpg
            $table->string('mime_type');              // image/jpeg, video/mp4, application/pdf
            $table->string('collection')->default('default'); // 'attachments', 'avatars'
            $table->unsignedBigInteger('size');       // File size in bytes

            // Media properties (dimensions, audio duration, thumbnails)
            $table->json('custom_properties')->nullable();

            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['model_type', 'model_id', 'collection']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medias');
    }
};
