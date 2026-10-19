<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Chat;
use App\Models\ChatMembers;
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
        $latestMessageIds = DB::table('messages')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('chat_id');

        return ChatMembers::query()
            ->where('chat_members.user_id', $userId)
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
            // Join chat user settings (pinned, muted, archived, draft)
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
                $join->on('latest_msg_ids.id', '=', DB::raw('(SELECT MAX(id) FROM messages WHERE chat_id = chat_members.chat_id)'));
            })
            ->leftJoin('messages as latest_msg', 'latest_msg.id', '=', 'latest_msg_ids.id')
            ->select([
                DB::raw('COALESCE(chats.slug, peer.username) as slug'),
                // Priority: Custom Contact Name -> Peer Name -> Chat Name
                DB::raw('COALESCE(user_contacts.name, chats.name, peer.name) as name'),
                'peer.id as direct_user_id',
                'chats.type',
                'chats.id as id',
                'chats.created_at',
                'latest_msg.content as last_message',
                'latest_msg.created_at as last_message_created_at',
                'latest_msg.sender_id as last_message_sender_id',
                'latest_msg.read_at as last_message_read_at',
                DB::raw('COALESCE(chat_media.id, profile_media.id) as profile_id'),
                // Chat user actions/settings
                DB::raw('COALESCE(chat_user_settings.is_pinned, 0) as pinned'),
                DB::raw('COALESCE(chat_user_settings.is_muted, 0) as muted'),
                DB::raw('COALESCE(chat_user_settings.is_archived, 0) as archived'),
                'chat_user_settings.draft',
                // Peer online status (active within last 5 minutes)
                // DB::raw('CASE WHEN peer.last_seen_at >= NOW() - INTERVAL 5 MINUTE THEN 1 ELSE 0 END as is_online'),
            ])
            ->selectSub(function ($query) use ($userId) {
                $query->from('messages')
                    ->whereColumn('messages.chat_id', 'chat_members.chat_id')
                    ->where('messages.sender_id', '!=', $userId)
                    ->whereNull('messages.read_at')
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

        $profileId = $chat->profile_id;
        $chat->profile_photo = $profileId ? route('media.public', ['id' => $profileId, 'thumbnail' => 1]) : null;
        unset($chat->profile_id);

        // Frontend Action Properties
        $chat->pinned = (bool) $chat->pinned;
        $chat->muted = (bool) $chat->muted;
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
        $currentUser = $req->user();

        $chatMember = ChatMembers::query()
            ->where('chat_id', $chatId)
            ->where('user_id', $currentUser->id)
            ->first();

        abort_if($chatMember == null, 404, 'chat not found or does not belong to you');

        $ok = $chatMember->delete();
        abort_if(!$ok, 400, 'failed to leave chat');

        return response()->json([
            'message' => 'chat removed successfully',
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

    /**
     * Toggle or set mute status for a chat.
     */
    public function toggleMute(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $req->validate([
            'is_muted' => 'required|boolean',
        ]);
        $chat = Chat::find($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = \App\Models\ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['is_muted' => $req->boolean('is_muted')]
        );

        return response()->json([
            'message' => 'Chat mute status updated successfully',
            'muted' => (bool) $setting->is_muted,
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
