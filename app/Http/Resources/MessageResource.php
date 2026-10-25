<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'sender_id' => $this->sender_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'reply_to_id' => $this->reply_to_id,
            'reply_to' => new MessageResource($this->whenLoaded('replyTo')),
            'forwarded_from_message_id' => $this->forwarded_from_message_id,
            'forwarded_from' => $this->whenLoaded('forwardedFromMessage', function () {
                $chat = $this->forwardedFromMessage->chat;
                $sender = $this->forwardedFromMessage->sender;
                if (!$chat) {
                    return null;
                }
                if ($chat && $chat->type === 'direct' && $sender) {
                    $chat->name = $chat->name ?? $sender->name;
                    $chat->slug = $chat->slug ?? $sender->username;
                    $chat->direct_user_id = $sender->id;

                    if ($sender->relationLoaded('profilePhoto') && $sender->profilePhoto) {
                        // Set 'profilePhoto' relation so ChatResource picks it up correctly
                        $chat->setRelation('profilePhoto', $sender->profilePhoto);
                    }
                }

                return new ChatResource($chat);
            }),
            
            'chat_id' => $this->chat_id,
            'chat' => new ChatResource(
                $this->whenLoaded('chat')
            ),
            'attachments' => MediaResource::collection(
                $this->whenLoaded('media')
            ),
            // 'attachments_count' => $this->whenCounted('media'),
            'attachments_count' => $this->when(
                $this->relationLoaded('media') || array_key_exists('media_count', $this->resource->getAttributes()),
                fn () => $this->relationLoaded('media') ? $this->media->count() : $this->media_count
            ),
        ];
    }
}