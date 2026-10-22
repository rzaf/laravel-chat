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
            'reply_to_id' => $this->reply_to_id,
            'reply_to' => new MessageResource($this->whenLoaded('replyTo')),
            'forwarded_from_message_id' => $this->forwarded_from_message_id,
            'forwarded_from' => $this->whenLoaded('forwardedFromMessage', function () {
                $chat = $this->forwardedFromMessage->chat;
                $sender = $this->forwardedFromMessage->sender;

                if ($chat && $chat->type === 'direct' && $sender) {
                    $chat->name = $chat->name ?? $sender->name;
                    $chat->slug = $chat->slug ?? $sender->username;
                    $chat->direct_user_id = $sender->id;
                }

                return new ChatResource($chat);
            }),
            
            'chat' => new ChatResource(
                $this->whenLoaded('chat')
            ),
            'attachments' => MediaResource::collection(
                $this->whenLoaded('media')
            ),
        ];
    }
}