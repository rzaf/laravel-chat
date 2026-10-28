<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as a member of a chat: the normal user fields plus their role.
 * Expects the user row to be selected with `chat_members.role as role`.
 */
class ChatMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(
            (new UserResource($this->resource))->resolve($request),
            ['role' => $this->role],
        );
    }
}
