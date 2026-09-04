<?php

namespace App\Traits;

use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function attachMedia($file, string $collection = 'default', string $disk = 'public')
    {
        return app(\App\Services\MediaService::class)->upload($this, $file, $collection, $disk);
    }
}