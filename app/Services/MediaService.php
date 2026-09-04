<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaService
{
    public function upload(
        Model $model,
        UploadedFile $file,
        string $collection = 'default',
        string $disk = 'public',
        ?int $userId = null
    ): Media {
        $uuidName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $directory = "{$collection}/{$model->id}";
        
        // Store file securely with generated UUID name
        $path = $file->storeAs($directory, $uuidName, $disk);

        return $model->media()->create([
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'collection' => $collection,
            'size' => $file->getSize(),
            'uploaded_by' => $userId ?? auth()->id(),
        ]);
    }
}