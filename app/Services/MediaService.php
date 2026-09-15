<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Format;

use kornrunner\Blurhash\Blurhash;


class MediaService
{
    public function upload(
        Model $model,
        UploadedFile $file,
        string $collection = 'default',
        string $disk = 'public',
        ?int $userId = null
    ): Media {
        $uuidName = Str::uuid();
        $directory = "{$collection}/{$model->id}";
        
        // Store file securely with generated UUID name
        $path = $file->storeAs($directory, $uuidName, $disk);

        $metaData = [];
        $width = null;
        $height = null;
        $mimeType = $file->getClientMimeType();
        $thumbPath = null;
        $blurhash = null;

        if (str_starts_with($mimeType, 'image/')) {
            $manager = ImageManager::usingDriver(Driver::class);
            $image = $manager->decodePath($file->path());
            $width = $image->width();
            $height = $image->height();
            $metaData = $image->exif();
            
            // creating the blurhash:// Creating the blurhash
            $blurImage = clone $image;
            $maxSize = 32;
            $aspectRatio = $image->width() / $image->height();
            $blurWidth = $aspectRatio >= 1 ? $maxSize : max(1, (int) round($maxSize * $aspectRatio));
            $blurHeight = $aspectRatio >= 1 ? max(1, (int) round($maxSize / $aspectRatio)) : $maxSize;

            $blurImage->resize($blurWidth, $blurHeight);

            $imagick = $blurImage->core()->native();
            $imagick->setImageColorspace(\Imagick::COLORSPACE_RGB);
            $pixelData = $imagick->exportImagePixels(0, 0, $blurWidth, $blurHeight, 'RGB', \Imagick::PIXEL_CHAR);

            $pixels = [];
            $index = 0;
            for ($y = 0; $y < $blurHeight; $y++) {
                $row = [];
                for ($x = 0; $x < $blurWidth; $x++) {
                    $row[] = [$pixelData[$index++], $pixelData[$index++], $pixelData[$index++]];
                }
                $pixels[] = $row;
            }

            $blurhash = Blurhash::encode($pixels, 4, 3);


            // $image->cover(100, 100, 'center');
            $image->scaleDown(width: 100);
            $encoded = $image->encodeUsingFormat(Format::JPEG, quality: 75);
            $thumbPath = $directory . '/' . $uuidName . '_thumb';
            // $encoded->save(Storage::disk($disk)->path('') . $thumbPath);
            Storage::disk($disk)->put($thumbPath, $encoded->toString());

        }


        $checksum = hash_file('sha256', $file->getRealPath());
        return $model->media()->create([
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'collection' => $collection,
            'size' => $file->getSize(),
            'uploaded_by' => $userId ?? auth()->id(),

            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
            'metadata' => $metaData,
            'thumb_path' => $thumbPath,
            'blurhash' => $blurhash,
        ]);
    }
}
