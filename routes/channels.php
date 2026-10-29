<?php

use App\Models\ChatMembers;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('Users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    return ChatMembers::where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->whereNull('deleted_at')
        ->exists();
});