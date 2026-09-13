<?php

use App\Models\Media;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;

class MediaThumbnailService {

    public function createThumbnail(Media $media)
    {
        if (!str_starts_with($media->mime_type,'image/')) {
            return;
        }
    
        $disk = Storage::disk($media->disk);
    
        $image = Image::read(
            $disk->get($media->path)
        );
    
    
        $image->cover(300,300);
    
    
        $thumbPath =
            dirname($media->path)
            .'/thumb_'
            .basename($media->path);
    
    
        $disk->put(
            $thumbPath,
            $image->encode()
        );
    
    
        $properties = $media->custom_properties ?? [];
    
        $properties['thumbnail'] = $thumbPath;
    
    
        $media->update([
            'custom_properties'=>$properties
        ]);
    }
}