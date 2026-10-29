<?php

namespace App\Events;

use App\Http\Resources\ChatResource;
use App\Models\Chat;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewChat implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Chat $chat) {}

    public function broadcastOn(): array
    {
        return $this->chat->members()
            ->whereNull('chat_members.deleted_at')
            ->pluck('users.id')
            ->map(fn($userId) => new PrivateChannel('Users.' . $userId))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'chat.new';
    }

    public function broadcastWith(): array
    {
        return ['chat' => (new ChatResource($this->chat))->resolve()];
    }
}
