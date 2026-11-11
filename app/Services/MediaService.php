<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver;
use kornrunner\Blurhash\Blurhash;

class MediaService
{
    public function __construct(private MediaProbeService $probe) {}

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
        $duration = null;
        $mimeType = $file->getClientMimeType();
        $thumbPath = null;
        $thumbMimeType = null;
        $blurhash = null;

        if (str_starts_with($mimeType, 'image/')) {
            $manager = ImageManager::usingDriver(Driver::class);
            $image = $manager->decodePath($file->path());
            $width = $image->width();
            $height = $image->height();
            $metaData = $image->exif();

            $blurhash = $this->makeBlurhash($image);

            $image->scaleDown(width: 100);
            $encoded = $image->encodeUsingFormat(Format::JPEG, quality: 75);
            $thumbPath = $directory . '/' . $uuidName . '_thumb';
            $thumbMimeType = 'image/jpeg';
            Storage::disk($disk)->put($thumbPath, $encoded->toString());
        } elseif (str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/')) {
            // Trust ffprobe, not the client-supplied mime type
            $info = $this->probe->probe($file->path());
            $duration = $info['duration'];

            $thumbBytes = null;
            if ($info['kind'] === 'video') {
                $width = $info['width'];
                $height = $info['height'];
                $thumbBytes = $this->probe->extractFrame($file->path(), $duration);
            } elseif ($info['kind'] === 'audio' && $info['has_cover']) {
                // embedded album art becomes the audio's thumbnail
                $thumbBytes = $this->probe->extractAudioCoverArt($file->path());
            }

            if (!empty($thumbBytes)) {
                try {
                    $blurhash = $this->makeBlurhash(
                        ImageManager::usingDriver(Driver::class)->decodeBinary($thumbBytes)
                    );
                    $thumbPath = $directory . '/' . $uuidName . '_thumb';
                    Storage::disk($disk)->put($thumbPath, $thumbBytes);
                    $thumbMimeType = 'image/jpeg';
                } catch (\Throwable $e) {
                    // a bad cover/frame must never fail the upload
                    report($e);
                    $thumbPath = null;
                    $blurhash = null;
                }
            }
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
            'duration' => $duration, // seconds, e.g. 12.345 (video + audio)
            'checksum' => $checksum,
            'metadata' => $metaData,
            'thumb_path' => $thumbPath,
            'thumb_mime_type' => $thumbMimeType,
            'blurhash' => $blurhash,
        ]);
    }

    public function link(Media $sourceMedia, Model $targetModel): Media
    {
        return $targetModel->media()->create([
            'disk' => $sourceMedia->disk,
            'path' => $sourceMedia->path, // Points to the exact same file path on disk
            'file_name' => $sourceMedia->file_name,
            'mime_type' => $sourceMedia->mime_type,
            'collection' => $sourceMedia->collection,
            'size' => $sourceMedia->size,
            'uploaded_by' => auth()->id() ?? $sourceMedia->uploaded_by,
            'width' => $sourceMedia->width,
            'height' => $sourceMedia->height,
            'duration' => $sourceMedia->duration,
            'checksum' => $sourceMedia->checksum,
            'metadata' => $sourceMedia->metadata,
            'thumb_path' => $sourceMedia->thumb_path, // Points to the same thumbnail path
            'thumb_mime_type' => $sourceMedia->thumb_mime_type,
            'blurhash' => $sourceMedia->blurhash,
        ]);
    }

    /** Blurhash of any Intervention image (works on a clone, the original is untouched). */
    private function makeBlurhash($image): string
    {
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

        return Blurhash::encode($pixels, 4, 3);
    }
}
