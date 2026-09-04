<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->model_type == User::class) {
            return [
                'id' => $this->id,
                'url' => route('media.get', [
                    'id' => $this->id
                ]),
            ];
        }
        return [
            'id' => $this->id,
            'name' => $this->file_name,
            'type' => $this->mime_type,
            'size' => $this->size,
            'collection' => $this->collection,
            'url' => route('media.get', [
                'id' => $this->id
            ]),
            'created_at' => $this->created_at,
        ];
    }
}