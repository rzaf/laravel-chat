<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->file_name,
            'slug' => $this->slug,
            'type' => $this->type,
            'is_private' => $this->is_private,
            'is_readonly' => $this->is_readonly,
            'creator' => $this->creator,
            'created_at' => $this->created_at,
            'direct_user_id' => $this->direct_user_id,
        ];
    }
}