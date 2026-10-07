<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'created_at' => $this->created_at,
            'profile_photo' => $this->when(
                $this->relationLoaded('profilePhoto') && $this->profilePhoto != null,
                // todo: handle private profiles ???
                route('media.public', ['id' => $this->profilePhoto->id ?? 0, 'thumbnail' => 1,]),
                ''
            ),
            'profiles' => MediaResource::collection(
                $this->whenLoaded('media')
            ),
            'bio' => $this->bio,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
