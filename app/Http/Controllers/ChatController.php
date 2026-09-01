<?php

namespace App\Http\Controllers;

use App\Models\ChatMembers;
use App\Models\Message;
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

        return ChatMembers::query()
            ->where('chat_members.user_id', $userId)
            ->join('chats', 'chats.id', '=', 'chat_members.chat_id')
            // Join the peer (other member) for direct chats
            ->leftJoin('chat_members as peer_members', function ($join) use ($userId) {
                $join->on('peer_members.chat_id', '=', 'chat_members.chat_id')
                    ->where('peer_members.user_id', '!=', $userId);
            })
            ->leftJoin('users as peer', 'peer.id', '=', 'peer_members.user_id')
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
            ])
            ->selectSub(function ($query) use ($userId) {
                $query->from('messages')
                    ->whereColumn('messages.chat_id', 'chat_members.chat_id')
                    ->where('messages.sender_id', '!=', $userId)
                    ->whereNull('messages.read_at')
                    ->selectRaw('COUNT(*)');
            }, 'unseen_messages_count')
            // Sort by latest message date; fallback to chat creation date if no messages exist
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->get()
            ->map(function ($chat) {
                $chat->last_message_created_at = $chat->last_message_created_at
                    ? \Carbon\Carbon::parse($chat->last_message_created_at)
                    : null;

                return $chat;
            });
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
