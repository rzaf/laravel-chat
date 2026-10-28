<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\ChatUserSetting;

/**
 * Shared membership / permission helpers for direct chats, groups and channels.
 *
 * Roles (chat_members.role): owner > admin > normal.
 *  - direct  : both members can send.
 *  - group   : everyone can send, unless chats.is_readonly (then only admin/owner).
 *  - channel : only admin/owner can send (always readonly for normal members).
 */
trait ChecksChatAccess
{
    protected function findChatOrFail(int|string $chatId): Chat
    {
        $chat = Chat::find($chatId);
        abort_if(!$chat, 404, 'chat not found');

        return $chat;
    }

    /** Active (not soft-deleted) membership row, or null. */
    protected function memberOf(int $userId, int $chatId): ?ChatMembers
    {
        return ChatMembers::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->first();
    }

    protected function memberOrFail(int $userId, int $chatId): ChatMembers
    {
        $member = $this->memberOf($userId, $chatId);
        abort_if(!$member, 403, 'Not Allowed');

        return $member;
    }

    protected function isAdminRole(?string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    protected function canSendIn(Chat $chat, ?ChatMembers $member): bool
    {
        if (!$member) {
            return false;
        }
        if ($chat->type === 'direct') {
            return true;
        }
        if ($this->isAdminRole($member->role)) {
            return true;
        }

        return $chat->type === 'group' && !$chat->is_readonly;
    }

    /**
     * Add a user to a chat (or restore a previous, soft-deleted membership).
     * Returns false when the user is already an active member.
     *
     * The unread counter starts from "now", so a new member isn't greeted
     * by the whole history as "unseen".
     */
    protected function addMember(Chat $chat, int $userId, string $role = 'normal'): bool
    {
        $member = ChatMembers::withTrashed()
            ->where('chat_id', $chat->id)
            ->where('user_id', $userId)
            ->first();

        if ($member && !$member->trashed()) {
            return false;
        }

        $member ??= new ChatMembers();
        $member->forceFill([
            'chat_id' => $chat->id,
            'user_id' => $userId,
            'role' => $role,
            'deleted_at' => null,
        ])->save();

        ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['last_read_at' => now()],
        );

        return true;
    }

    /** Soft-delete a membership (same mechanism the chat list already relies on). */
    protected function removeMember(int $chatId, int $userId): void
    {
        ChatMembers::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->update(['deleted_at' => now()]);
    }
}
