<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\ChatUserSetting;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * @authenticated
 * @group Chats
 */
class ChatController extends Controller
{
    /**
     * Build the shared base query for fetching chats with all joins and computed fields.
     */
    protected function baseChatQuery(int $userId)
    {
        // Latest active message subquery (excluding deleted messages)
        $latestMessageIds = DB::table('messages')
            ->select('chat_id', DB::raw('MAX(id) as id'))
            ->whereNull('deleted_at')
            ->groupBy('chat_id');

        return ChatMembers::query()
            ->where('chat_members.user_id', $userId)
            ->whereNull('chat_members.deleted_at')
            ->join('chats', 'chats.id', '=', 'chat_members.chat_id')
            // Join peer (other member) for direct chats
            ->leftJoin('chat_members as peer_members', function ($join) use ($userId) {
                $join->on('peer_members.chat_id', '=', 'chat_members.chat_id')
                    ->where('peer_members.user_id', '!=', $userId);
            })
            ->leftJoin('users as peer', 'peer.id', '=', 'peer_members.user_id')
            // Join user_contacts for custom contact names in direct chats
            ->leftJoin('user_contacts', function ($join) use ($userId) {
                $join->on('user_contacts.user_id', '=', 'peer.id')
                    ->where('user_contacts.owner', '=', $userId);
            })
            // Join chat user settings (pinned, muted, archived, draft, cleared_at)
            ->leftJoin('chat_user_settings', function ($join) use ($userId) {
                $join->on('chat_user_settings.chat_id', '=', 'chats.id')
                    ->where('chat_user_settings.user_id', '=', $userId);
            })
            ->leftJoin('medias as profile_media', function ($join) {
                $join->on('profile_media.model_id', '=', 'peer.id')
                    ->where('profile_media.model_type', User::class)
                    ->where('profile_media.collection', 'profiles')
                    ->whereRaw('profile_media.id = (
                        SELECT MAX(m.id) FROM medias m
                        WHERE m.model_type = ? AND m.model_id = peer.id AND m.collection = ?
                    )', [User::class, 'profiles']);
            })
            ->leftJoin('medias as chat_media', function ($join) {
                $join->on('chat_media.model_id', '=', 'chats.id')
                    ->where('chat_media.model_type', Chat::class)
                    ->whereRaw('chat_media.id = (
                        SELECT MAX(m.id) FROM medias m
                        WHERE m.model_type = ? AND m.model_id = chats.id
                    )', [Chat::class]);
            })
            ->leftJoinSub($latestMessageIds, 'latest_msg_ids', function ($join) {
                $join->on('latest_msg_ids.chat_id', '=', 'chats.id');
            })
            ->leftJoin('messages as latest_msg', 'latest_msg.id', '=', 'latest_msg_ids.id')
            // // Hide chat from list if it was cleared and no new messages arrived after cleared_at
            // ->where(function ($query) {
            //     $query->whereNull('chat_user_settings.cleared_at')
            //         ->orWhereColumn('latest_msg.created_at', '>', 'chat_user_settings.cleared_at');
            // })
            ->select([
                DB::raw('COALESCE(chats.slug, peer.username) as slug'),
                // Priority: Custom Contact Name -> Peer Name -> Chat Name
                DB::raw('COALESCE(user_contacts.name, chats.name, peer.name) as name'),
                'peer.id as direct_user_id',
                'chats.type',
                'chats.id as id',
                'chats.created_at',
                // Respect cleared_at for message preview text as well
                DB::raw('
                    CASE 
                        WHEN chat_user_settings.cleared_at IS NOT NULL AND latest_msg.created_at <= chat_user_settings.cleared_at 
                        THEN NULL 
                        ELSE latest_msg.content 
                    END as last_message'),
                DB::raw('
                    CASE 
                        WHEN chat_user_settings.cleared_at IS NOT NULL AND latest_msg.created_at <= chat_user_settings.cleared_at 
                        THEN NULL 
                        ELSE latest_msg.created_at 
                    END as last_message_created_at'),
                'latest_msg.sender_id as last_message_sender_id',
                DB::raw('
                    CASE 
                        WHEN latest_msg.id IS NULL THEN 1
                        WHEN latest_msg.sender_id = ' . $userId . ' THEN 1
                        WHEN chat_user_settings.last_read_at IS NOT NULL AND latest_msg.created_at <= chat_user_settings.last_read_at THEN 1
                        ELSE 0 
                    END as is_latest_message_read'),
                DB::raw('COALESCE(chat_media.id, profile_media.id) as profile_id'),
                // Chat user actions/settings
                DB::raw('COALESCE(chat_user_settings.is_pinned, 0) as pinned'),
                DB::raw('COALESCE(chat_user_settings.is_muted, 0) as muted'),
                DB::raw('COALESCE(chat_user_settings.is_archived, 0) as archived'),
                'chat_user_settings.draft',
                'chat_user_settings.last_read_at AS last_read_at',
            ])
            ->selectSub(function ($query) use ($userId) {
                $query->from('messages')
                    ->whereColumn('messages.chat_id', 'chat_members.chat_id')
                    ->where('messages.sender_id', '!=', $userId)
                    ->whereNull('messages.deleted_at')
                    // Check if message is newer than user's last_read_at (or if last_read_at is null)
                    ->where(function ($q) use ($userId) {
                        $q->where('messages.created_at', '>', function ($sub) use ($userId) {
                            $sub->from('chat_user_settings')
                                ->whereColumn('chat_user_settings.chat_id', 'messages.chat_id')
                                ->where('chat_user_settings.user_id', $userId)
                                ->select('last_read_at');
                        })
                            ->orWhereNotExists(function ($sub) use ($userId) {
                                $sub->from('chat_user_settings')
                                    ->whereColumn('chat_user_settings.chat_id', 'messages.chat_id')
                                    ->where('chat_user_settings.user_id', $userId)
                                    ->whereNotNull('chat_user_settings.last_read_at');
                            });
                    })
                    // Ignore messages older than cleared_at
                    ->where(function ($q) use ($userId) {
                        $q->whereNotExists(function ($sub) use ($userId) {
                            $sub->from('chat_user_settings')
                                ->whereColumn('chat_user_settings.chat_id', 'messages.chat_id')
                                ->where('chat_user_settings.user_id', $userId)
                                ->whereNotNull('chat_user_settings.cleared_at')
                                ->whereColumn('messages.created_at', '<=', 'chat_user_settings.cleared_at');
                        });
                    })
                    ->selectRaw('COUNT(*)');
            }, 'unseen_messages_count')
            ->selectSub(function ($query) {
                $query->from('medias')
                    ->where('medias.model_type', Message::class)
                    ->whereColumn('medias.model_id', 'latest_msg.id')
                    ->selectRaw('COUNT(*)');
            }, 'latest_message_attachments_count');
    }

    /**
     * Format chat record attributes and compute frontend state variables.
     */
    protected function formatChat($chat)
    {
        $currentUserId = auth()->id();

        $chat->last_message_created_at = $chat->last_message_created_at
            ? Carbon::parse($chat->last_message_created_at)
            : null;

        $chat->last_read_at = $chat->last_read_at
            ? Carbon::parse($chat->last_read_at)
            : null;

        $profileId = $chat->profile_id;
        $chat->profile_photo = $profileId ? route('media.public', ['id' => $profileId, 'thumbnail' => 1]) : null;
        unset($chat->profile_id);

        // Frontend Action Properties
        $chat->pinned = (bool) $chat->pinned;
        $chat->muted = (bool) $chat->muted;
        $chat->muted_until = $chat->muted_until
            ? Carbon::parse($chat->muted_until)
            : null;
        $chat->archived = (bool) $chat->archived;
        // $chat->is_online = (bool) $chat->is_online;
        $chat->is_typing = false;

        $chat->last_message_is_mine = $chat->last_message_sender_id == $currentUserId;

        if ($chat->last_message_is_mine) {
            $chat->last_message_status = $chat->last_message_read_at ? 'seen' : 'delivered';
        } else {
            $chat->last_message_status = 'seen';
        }

        unset($chat->last_message_sender_id, $chat->last_message_read_at);

        return $chat;
    }

    public function index(Request $req)
    {
        $userId = $req->user()->id;

        return $this->baseChatQuery($userId)
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->get()
            ->map(fn($chat) => $this->formatChat($chat));
    }

    public function getChat(Request $req, string $slug)
    {
        $userId = $req->user()->id;

        $chat = $this->baseChatQuery($userId)
            ->whereRaw('COALESCE(chats.slug, peer.username) = ?', [$slug])
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->first();

        if ($chat) {
            $this->formatChat($chat);
        }

        $user = (($chat->type ?? 'direct') != 'direct') ? null : User::where('username', $slug)->first();

        return response()->json([
            'chat' => $chat,
            'user' => new UserResource($user),
        ], 200);
    }

    public function destroy(Request $req, string $chatId)
    {
        $req->validate([
            'delete_for_everyone' => 'sometimes|boolean',
        ]);
        $currentUser = $req->user();
        // 1. Find the chat and verify membership
        $chat = Chat::query()->where('id', $chatId)->first();
        if (!$chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }
        $currentUserMemberRow = $chat->members()->where('user_id', $currentUser->id)->first();
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'You are not a member of this chat'], 403);
        }
        $deleteForEveryone = $req->boolean('delete_for_everyone', false);

        // ==========================================
        // SCENARIO A: DELETE / DESTROY FOR EVERYONE
        // ==========================================
        if ($deleteForEveryone) {
            // If it's a group chat, ensure user is owner
            if ($chat->type === 'group') {
                $role = $currentUserMemberRow->role;
                if (!in_array($role, ['owner'])) {
                    return response()->json(['message' => 'Only owner can delete group chats for everyone.'], 403);
                }
            }
            // Soft delete all messages in the chat globally
            $chat->messages()->delete();
            // Soft delete the chat globally
            $chat->delete();
            // delete chat member for current user so it doesn't appear in users chat list
            ChatMembers::query()->where([
                'chat_id' => $chat->id,
            ])->update(['deleted_at' => now()]);
            return response()->json([
                'message' => 'Chat and its messages deleted for everyone successfully.',
            ], 200);
        }

        // ==========================================
        // SCENARIO B: DELETE "JUST FOR ME"
        // ==========================================
        if ($chat->type === 'group') {
            $role = $currentUserMemberRow->role;
            if (in_array($role, ['owner'])) {
                return response()->json(['message' => 'Owner cant leave the group!'], 403);
            }
            // For groups, "deleting for me" means leaving the group entirely
            $chat->members()->detach($currentUser->id);
            return response()->json([
                'message' => 'You have left the group chat.',
            ], 200);
        }

        // For direct chats, we don't want to destroy the record globally 
        // because the other person still needs it. We hide it and clear the history for this user.
        ChatUserSetting::updateOrCreate([
            'user_id' => $currentUser->id,
            'chat_id' => $chat->id
        ], [
            'cleared_at' => now(), // Marks the point from which past messages are hidden for this user
        ]);
        // delete chat member for current user so it doesn't appear in users chat list
        ChatMembers::query()->where([
            'user_id' => $currentUser->id,
            'chat_id' => $chat->id,
        ])->update(['deleted_at' => now()]);

        return response()->json([
            'message' => 'Chat removed from your list and history cleared.',
        ], 200);
    }


    public function clearHistory(Request $req, string $chatId)
    {
        $req->validate([
            'delete_for_everyone' => 'sometimes|boolean',
        ]);
        $currentUser = $req->user();
        $chat = Chat::query()->where('id', $chatId)->first();
        if (!$chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }
        $currentUserMemberRow = $chat->members()->where('user_id', $currentUser->id)->first();
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'You are not a member of this chat'], 403);
        }
        if ($chat->type === 'group') {
            return response()->json(['message' => 'Cant clear history of groups'], 403);
        }
        $deleteForEveryone = $req->boolean('delete_for_everyone', false);
        if ($deleteForEveryone) {
            // Soft delete all messages in the chat globally
            $chat->messages()->delete();
            return response()->json([
                'message' => 'Chat messages deleted for everyone successfully.',
            ], 200);
        }
        ChatUserSetting::updateOrCreate([
            'user_id' => $currentUser->id,
            'chat_id' => $chat->id
        ], [
            'cleared_at' => now(), // Marks the point from which past messages are hidden for this user
        ]);

        return response()->json([
            'message' => 'Chat history cleared.',
        ], 200);
    }

    /**
     * Toggle or set pin status for a chat.
     */
    public function togglePin(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $req->validate([
            'is_pinned' => 'required|boolean',
        ]);
        $chat = Chat::find($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = \App\Models\ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['is_pinned' => $req->boolean('is_pinned')]
        );

        return response()->json([
            'message' => 'Chat pin status updated successfully',
            'pinned' => (bool) $setting->is_pinned,
        ]);
    }

    public static array $muteDurations = ['15m' => 15, '1h' => 60, '8h' => 8 * 60, '24h' => 24 * 60, '1w' => 7 * 24 * 60, 'forever' => null];

    /**
     * set mute status for a chat.
     */
    public function setMute(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $req->validate([
            'duration' => 'nullable|in:' . implode(',', array_keys(self::$muteDurations)),
        ]);
        $chat = Chat::find($chatId);
        $this->verifyChatMembership($userId, $chat->id);
        $mutedUntil = null;
        if ($req->duration && $req->duration != 'forever') {
            $mutedUntil = now()->addMinutes(self::$muteDurations[$req->duration]);
        }

        $setting = \App\Models\ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['is_muted' => $req->duration !== null, 'muted_until' => $mutedUntil]
        );

        return response()->json([
            'message' => 'Chat mute status updated successfully',
            'muted' => (bool) $setting->is_muted,
            'muted_until' => $setting->muted_until,
        ]);
    }

    /**
     * Toggle or set archive status for a chat.
     */
    public function toggleArchive(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $req->validate([
            'is_archived' => 'required|boolean',
        ]);
        $chat = Chat::find($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = \App\Models\ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['is_archived' => $req->boolean('is_archived')]
        );

        return response()->json([
            'message' => 'Chat archive status updated successfully',
            'archived' => (bool) $setting->is_archived,
        ]);
    }

    /**
     * mark as read all message of a chat
     */
    public function markAsReadChat(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $chat = Chat::find($chatId);
        abort_if(!$chat, 404, 'chat not found');
        $this->verifyChatMembership($userId, $chat->id);

        $userSetting = ChatUserSetting::query()->firstOrCreate([
            'user_id' => $req->user()->id,
            'chat_id' =>  $chat->id
        ]);
        $userSetting->update([
            'last_read_at' => now()
        ]);
        return response()->json([
            'message' => 'messages marked as read',
        ], 200);
    }

    /**
     * Save or clear the draft text for a chat.
     */
    public function updateDraft(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $validated = $req->validate([
            'draft' => 'required|string|max:1024',
        ]);
        $chat = Chat::find($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = \App\Models\ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['draft' => $validated['draft']] // Can be string or null
        );

        return response()->json([
            'message' => 'Draft updated successfully',
            'draft' => $setting->draft,
        ]);
    }

    /**
     * Helper to verify user belongs to the chat.
     */
    protected function verifyChatMembership(int $userId, int $chatId)
    {
        $isMember = ChatMembers::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        abort_if(!$isMember, 403, 'Not Allowed');
    }
}
