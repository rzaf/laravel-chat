<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @authenticated
 * @group Chats
 * 
 *
 */
class ChatController extends Controller
{
        
    public function index(Request $req)
    {
        $userId = $req->user()->id;

        // Subquery to retrieve only the latest message ID per chat
        $latestMessageIds = DB::table('messages')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('chat_id');
        // $latestUserMediaIds = DB::table('medias')
        //     ->select(DB::raw('MAX(id) as id'))
        //     ->where('model_type', User::class)
        //     ->where('collection', 'avatars')
        //     ->groupBy('model_id');

        // $latestChatMediaIds = DB::table('medias')
        //     ->select(DB::raw('MAX(id) as id'))
        //     ->where('model_type', Chat::class)
        //     ->groupBy('model_id');

        return ChatMembers::query()
            ->where('chat_members.user_id', $userId)
            ->join('chats', 'chats.id', '=', 'chat_members.chat_id')
            // Join the peer (other member) for direct chats
            ->leftJoin('chat_members as peer_members', function ($join) use ($userId) {
                $join->on('peer_members.chat_id', '=', 'chat_members.chat_id')
                    ->where('peer_members.user_id', '!=', $userId);
            })
            ->leftJoin('users as peer', 'peer.id', '=', 'peer_members.user_id')
            ->leftJoin('medias as profile_media', function ($join) {
                $join->on('profile_media.model_id', '=', 'peer.id')
                    ->where('profile_media.model_type', User::class)
                    ->where('profile_media.collection', 'profiles')
                    ->whereRaw('profile_media.id = (
                        SELECT MAX(m.id)
                        FROM medias m
                        WHERE m.model_type = ?
                        AND m.model_id = peer.id
                        AND m.collection = ?
                    )', [User::class, 'profiles']);
            })
            ->leftJoin('medias as chat_media', function ($join) {
                $join->on('chat_media.model_id', '=', 'chats.id')
                    ->where('chat_media.model_type', Chat::class)
                    ->whereRaw('chat_media.id = (
                        SELECT MAX(m.id)
                        FROM medias m
                        WHERE m.model_type = ?
                        AND m.model_id = chats.id
                    )', [Chat::class]);
            })
            // Join max message IDs subquery, then join the actual message details
            ->leftJoinSub($latestMessageIds, 'latest_msg_ids', function ($join) {
                $join->on('latest_msg_ids.id', '=', DB::raw('(SELECT MAX(id) FROM messages WHERE chat_id = chat_members.chat_id)'));
            })
            ->leftJoin('messages as latest_msg', 'latest_msg.id', '=', 'latest_msg_ids.id')
            ->select([
                DB::raw('COALESCE(chats.slug, peer.username) as slug'),
                DB::raw('COALESCE(chats.name, peer.name) as name'),
                'peer.id as direct_user_id',
                'chats.type',
                'chats.id as id',
                'chats.created_at',
                'latest_msg.content as last_message',
                'latest_msg.created_at as last_message_created_at',
                DB::raw('COALESCE(chat_media.id, profile_media.id) as profile_id'),
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
            }, 'latest_message_attachments_count')
            // Sort by latest message date; fallback to chat creation date if no messages exist
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->get()
            ->map(function ($chat) {
                $chat->last_message_created_at = $chat->last_message_created_at
                    ? \Carbon\Carbon::parse($chat->last_message_created_at)
                    : null;
                $profileId = $chat->profile_id;
                // todo: handle private profiles ???
                $chat->profile_photo = $profileId ? route('media.public', [ 'id' => $profileId ?? 0, 'thumbnail' => 1,]) : null;
                unset($chat->profile_id);
                return $chat;
            });
    }

        
    /**
     * getChat
     * 
     * @urlParam slug string chat slug. No-example 
     * 
     * @response 200
     * @response 401
     */
    public function getChat(Request $req, string $slug)
    {
        $userId = $req->user()->id;

        // Subquery to retrieve only the latest message ID per chat
        $latestMessageIds = DB::table('messages')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('chat_id');

        $chat = ChatMembers::query()
            ->where('chat_members.user_id', $userId)
            ->join('chats', 'chats.id', '=', 'chat_members.chat_id')
            // Join the peer (other member) for direct chats
            ->leftJoin('chat_members as peer_members', function ($join) use ($userId) {
                $join->on('peer_members.chat_id', '=', 'chat_members.chat_id')
                    ->where('peer_members.user_id', '!=', $userId);
            })
            ->leftJoin('users as peer', 'peer.id', '=', 'peer_members.user_id')
            ->leftJoin('medias as profile_media', function ($join) {
                $join->on('profile_media.model_id', '=', 'peer.id')
                    ->where('profile_media.model_type', User::class)
                    ->where('profile_media.collection', 'profiles')
                    ->whereRaw('profile_media.id = (
                        SELECT MAX(m.id)
                        FROM medias m
                        WHERE m.model_type = ?
                        AND m.model_id = peer.id
                        AND m.collection = ?
                    )', [User::class, 'profiles']);
            })
            ->leftJoin('medias as chat_media', function ($join) {
                $join->on('chat_media.model_id', '=', 'chats.id')
                    ->where('chat_media.model_type', Chat::class)
                    ->whereRaw('chat_media.id = (
                        SELECT MAX(m.id)
                        FROM medias m
                        WHERE m.model_type = ?
                        AND m.model_id = chats.id
                    )', [Chat::class]);
            })
            // Join max message IDs subquery, then join the actual message details
            ->leftJoinSub($latestMessageIds, 'latest_msg_ids', function ($join) {
                $join->on('latest_msg_ids.id', '=', DB::raw('(SELECT MAX(id) FROM messages WHERE chat_id = chat_members.chat_id)'));
            })
            ->leftJoin('messages as latest_msg', 'latest_msg.id', '=', 'latest_msg_ids.id')
            ->select([
                DB::raw('COALESCE(chats.slug, peer.username) as slug'),
                DB::raw('COALESCE(chats.name, peer.name) as name'),
                'peer.id as direct_user_id',
                'chats.type',
                'chats.id as id',
                'chats.created_at',
                'latest_msg.content as last_message',
                'latest_msg.created_at as last_message_created_at',
                DB::raw('COALESCE(chat_media.id, profile_media.id) as profile_id'),
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
            }, 'latest_message_attachments_count')
            // Sort by latest message date; fallback to chat creation date if no messages exist
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->whereRaw('COALESCE(chats.slug, peer.username) = ?', [$slug]) // checking slug
            ->first();
        if ($chat != null) {
            $chat->last_message_created_at = $chat->last_message_created_at
                ? \Carbon\Carbon::parse($chat->last_message_created_at)
                : null;
            $profileId = $chat->profile_id;
            // todo: handle private profiles ???
            $chat->profile_photo = $profileId ? route('media.public', [ 'id' => $profileId ?? 0, 'thumbnail' => 1,]) : null;
            unset($chat->profile_id);
        }
        $user = ($chat->type ?? 'direct') != 'direct' ? null : User::where('username', $slug)->first();
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
}
