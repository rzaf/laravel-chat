<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewPrivateMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
    }

    public function broadcastOn(): array
    {
        $recipient = $this->message->chat
            ->members()
            ->whereKeyNot($this->message->sender_id)
            ->first();

        return [
            new PrivateChannel('Users.' . ($recipient?->id ?? $this->message->sender_id)),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'content' => $this->message->content,
            'from' => $this->message->sender_id,
            'created_at' => $this->message->created_at,
            'updated_at' => $this->message->updated_at,
            'read_at' => $this->message->read_at,
        ];
    }
}
