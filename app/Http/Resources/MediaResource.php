<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class MediaResource extends JsonResource
{
    public static int $EXPIRE_MINUTES = 2;

    public function toArray(Request $request): array
    {
        $expire = now()->addMinutes(static::$EXPIRE_MINUTES);
        if ($this->model_type == User::class) {
            return [
                'id' => $this->id,
                'url' => route('media.public', ['id' => $this->id]),
                'thumbnail_url' => route('media.public', ['id' => $this->id,'thumbnail' => true,]),
                'expire' => $expire->toISOString(),
                'width' => $this->width,
                'height' => $this->height,
                'blurhash' => $this->blurhash,
                'created_at' => $this->created_at,
            ];
        }
        return [
            'id' => $this->id,
            'name' => $this->file_name,
            'type' => $this->mime_type,
            'size' => $this->size,
            'collection' => $this->collection,
            'url' => URL::temporarySignedRoute('media.private', $expire, ['id' => $this->id]),
            'thumbnail_url' => URL::temporarySignedRoute('media.private',$expire,['id' => $this->id,'thumbnail' => true,]),
            'expire' => $expire->toISOString(),
            'width' => $this->width,
            'height' => $this->height,
            'blurhash' => $this->blurhash,
            'created_at' => $this->created_at,
        ];
    }
}