<?php

namespace App\Http\Controllers;

use App\Events\NewPrivateMessage;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;


/**
 * @authenticated
 * @group Messages
 *
 * 
 */
class MessageController extends Controller
{
    protected function directChatForUsers(User $userA, User $userB): Chat
    {
        $chatIds = ChatMembers::query()
            ->select('chat_id')
            ->whereIn('user_id', [$userA->id, $userB->id])
            ->groupBy('chat_id')
            ->havingRaw('COUNT(DISTINCT user_id) = 2')
            ->pluck('chat_id');

        $chat = Chat::query()
            ->whereIn('id', $chatIds)
            ->where('type', 'direct')
            ->first();

        if ($chat) {
            return $chat;
        }

        $chat = Chat::create([
            'creator' => $userA->id,
            'type' => 'direct',
            'is_private' => true,
            'is_readonly' => false,
            'name' => null,
            'slug' => null,
        ]);

        $chat->members()->sync([
            $userA->id => ['role' => 'owner'],
            $userB->id => ['role' => 'normal'],
        ]);

        return $chat;
    }

    public function getDirectMessages(Request $request, string $username)
    {
        $currentUser = $request->user();

        $otherUser = User::where('username', $username)->firstOrFail();

        abort_if(
            $currentUser->id === $otherUser->id,
            400,
            'You cannot message yourself'
        );

        $chat = Chat::query()
            ->where('type', 'direct')
            ->whereHas('members', function ($query) use ($currentUser) {
                $query->where('users.id', $currentUser->id);
            })
            ->whereHas('members', function ($query) use ($otherUser) {
                $query->where('users.id', $otherUser->id);
            })
            ->first();

        // abort_if(!$chat, 400, 'Direct chat not found');
        // use Illuminate\Pagination\Paginator;

        if ($chat === null) {
            return new \Illuminate\Pagination\Paginator([], 20, 1);
        }

        return Message::query()
            ->where('chat_id', $chat->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20);
    }

    // public function index(Request $req, string $username)
    // {
    //     $currentUser = $req->user();
    //     $user = User::firstWhere(['username' => $username]);
    //     abort_if($user == null, 404, 'user not found');
    //     abort_if($currentUser->username === $username, 400, 'cant message yourself');

    //     $chat = $this->directChatForUsers($currentUser, $user);

    //     return Message::query()
    //         ->where('chat_id', $chat->id)
    //         ->orderByDesc('created_at')
    //         ->orderByDesc('id')
    //         ->simplePaginate(20)
    //         ->through(function ($message) {
    //             $message->from = $message->sender_id;
    //             $message->to = $message->chat->members->firstWhere('id', '!=', $message->sender_id)?->id;
    //             return $message;
    //         });
    // }

    public function storeDirectMessage(Request $req, string $username)
    {
        $user = User::firstWhere(['username' => $username]);
        abort_if($user == null, 404, 'user not found');
        abort_if($req->user()->username == $username, 400, 'cant message yourself');

        $validated = $req->validate([
            'content' => 'required|string|max:1024',
        ]);

        $chat = $this->directChatForUsers($req->user(), $user);
        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_id' => $req->user()->id,
            'content' => $validated['content'],
        ]);

        $message->load('chat');
        $message->chat->name = $message->chat->name ?? $user->name;
        $message->chat->slug = $message->chat->slug ?? $user->username;
        $message->chat->direct_user_id = $user->id;

        try {
            event(new NewPrivateMessage($message));
        } catch (\Throwable $th) {
        }

        return response()->json([
            'message' => 'message created',
            'data' => $message,
        ], 201);
    }

    public function update(Request $req, string $id)
    {
        $validated = $req->validate([
            'content' => 'required|string|max:1024',
        ]);

        $ok = Message::where('id', $id)
            ->where('sender_id', $req->user()->id)
            ->update(['content' => $validated['content']]);

        abort_if(!$ok, 404, 'message not found');

        return response()->json([
            'message' => 'message updated',
        ], 200);
    }

    public function markAsRead(Request $req, string $id)
    {
        $message = Message::find($id);
        abort_if(!$message, 404, 'message not found');

        $recipientId = $message->chat->members()->whereKeyNot($message->sender_id)->first()?->id;
        abort_if($recipientId != $req->user()->id, 403, 'you did not receive this message');

        $ok = $message->update(['read_at' => now()]);
        abort_if(!$ok, 400, 'failed to mark message as read');

        return response()->json([
            'message' => 'message marked as read',
        ], 200);
    }

    public function markAsReadAll(Request $req)
    {
        $user = User::firstWhere('username', $req->username);
        abort_if(!$user, 404, 'user not found');

        $chat = $this->directChatForUsers($user, $req->user());
        $ok = Message::where('chat_id', $chat->id)
            ->where('sender_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        abort_if(!$ok, 400, 'failed to mark message as read');

        return response()->json([
            'message' => 'message marked as read',
        ], 200);
    }

    public function destroy(Request $req, string $id)
    {
        $message = Message::findOrFail($id);
        abort_unless($message->sender_id === $req->user()->id, 403, 'Unauthorized');
        $message->delete();

        return response()->json([
            'message' => 'message deleted',
        ], 200);
    }
}

