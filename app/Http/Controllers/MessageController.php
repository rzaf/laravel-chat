<?php

namespace App\Http\Controllers;

use App\Events\NewPrivateMessage;
use App\Http\Resources\MessageResource;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\ChatUserSetting;
use App\Models\Message;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @authenticated
 * @group Messages
 *
 * 
 */
class MessageController extends Controller
{
    public static $mimes = [
        'jpg',
        'jpeg',
        'webp',
        'gif',
        'png',
        'mp3',
        'mp4',
        'pdf',
        'doc',
        'docx',
        'txt'
    ];


    protected function directChatForUsers(User $userA, User $userB): Chat
    {
        $chatIds = ChatMembers::withTrashed()
            ->select('chat_id')
            ->whereIn('user_id', [$userA->id, $userB->id])
            ->groupBy('chat_id')
            ->havingRaw('COUNT(DISTINCT user_id) = 2')
            ->pluck('chat_id');

        $chat = Chat::query()
            ->withTrashed()
            ->whereIn('id', $chatIds)
            ->where('type', 'direct')
            ->first();

        if ($chat) {
            ChatMembers::withTrashed()
                ->where('chat_id', $chat->id)
                ->whereIn('user_id', [$userA->id, $userB->id])
                ->restore();
            if ($chat->deleted_at != null) {
                $chat->restore();
            }
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

        abort_if($currentUser->id === $otherUser->id, 400, 'You cannot message yourself');

        $chat = Chat::query()
            ->where('type', 'direct')
            ->whereHas('members', function ($query) use ($currentUser) {
                $query->where('users.id', $currentUser->id);
            })
            ->whereHas('members', function ($query) use ($otherUser) {
                $query->where('users.id', $otherUser->id);
            })
            ->first();
        if ($chat === null) {
            return new \Illuminate\Pagination\Paginator([], 20, 1);
        }
        // Fetch user settings to check if history was cleared
        $settings = ChatUserSetting::where('user_id', $currentUser->id)
            ->where('chat_id', $chat->id)
            ->first();
        $query = Message::query()
            ->with(['media', 'replyTo.sender', 'forwardedFromMessage.sender', 'forwardedFromMessage.chat'])
            ->where('chat_id', $chat->id)
            // Exclude messages deleted individually by this user ("Delete for Me")
            ->whereNotExists(function ($subQuery) use ($currentUser) {
                $subQuery->select(DB::raw(1))
                    ->from('message_user_deletions')
                    ->whereColumn('message_user_deletions.message_id', 'messages.id')
                    ->where('message_user_deletions.user_id', $currentUser->id);
            });
        // If user cleared the chat history, ignore messages up to that timestamp
        if ($settings && $settings->cleared_at) {
            $query->where('created_at', '>', $settings->cleared_at);
        }
        return MessageResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->simplePaginate(20)
        );
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
    /**
     * Send message
     * 
     * @bodyParam content string optional The message content. Example: message
     * @bodyParam attachments[0] file optional First file attachment.
     * @bodyParam attachments[1] file optional Second file attachment.     
     * @bodyParam reply_to_id integer optional The ID of the message being replied to.
     * @bodyParam forwarded_from_message_id integer optional The ID of the message being forwarded.
     * 
     * @response 200 {}
     */
    public function storeDirectMessage(Request $req, string $username, MediaService $mediaService)
    {
        $currentUser = $req->user();
        $user = User::firstWhere(['username' => $username]);
        abort_if($user == null, 404, 'user not found');
        abort_if($currentUser->username == $username, 400, 'cant message yourself');

        $validated = $req->validate([
            'content' => 'required_without_all:attachments,forwarded_from_message_id|string|max:1024',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:102400|mimes:' . implode(',', static::$mimes),
            'reply_to_id' => 'nullable|exists:messages,id',
            'forwarded_from_message_id' => 'nullable|exists:messages,id',
        ]);

        try {
            DB::beginTransaction();
            $chat = $this->directChatForUsers($currentUser, $user);

            // Optional Security check: ensure reply_to_id belongs to a chat the user is part of
            if (!empty($validated['reply_to_id'])) {
                $replyMessage = Message::find($validated['reply_to_id']);
                // Ensure target message belongs to this current chat or user has access
                abort_if($replyMessage && $replyMessage->chat_id !== $chat->id, 403, 'Cannot reply to a message from another chat');
            }
            // Determine content: if it's a forward, fetch content directly from the original message model
            $content = $validated['content'] ?? '';
            $forwardedFromMessageId = null;
            if (!empty($validated['forwarded_from_message_id'])) {
                $sourceMessage = Message::find($validated['forwarded_from_message_id']);
                abort_if(!$sourceMessage, 404, 'Source message to forward not found');
                $content = $sourceMessage->content;
                // If the source message was *also* a forward, point to its original root message ID,
                // otherwise point directly to the source message.
                $forwardedFromMessageId = $sourceMessage->forwarded_from_message_id ?? $sourceMessage->id;
            }

            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_id' => $currentUser->id,
                'content' => $content,
                'reply_to_id' => $validated['reply_to_id'] ?? null,
                'forwarded_from_message_id' => $forwardedFromMessageId,
            ]);

            if ($req->hasFile('attachments')) {
                foreach ($req->file('attachments') as $file) {
                    $mediaService->upload(
                        model: $message,
                        file: $file,
                        collection: 'attachments',
                        disk: 'private' // Protected access
                    );
                }
            }
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
            // abort(500, $th->getMessage());
        }

        $message->load(['chat', 'media', 'replyTo.sender', 'forwardedFromMessage.sender', 'forwardedFromMessage.chat']);
        $message->chat->name = $message->chat->name ?? $user->name;
        $message->chat->slug = $message->chat->slug ?? $user->username;
        $message->chat->direct_user_id = $user->id;

        try {
            event(new NewPrivateMessage($message));
        } catch (\Throwable $th) {
        }

        return response()->json([
            'message' => 'message created',
            'data' => new MessageResource($message),
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

    public function markAsReadMessage(Request $req, string $messageId)
    {
        $user = $req->user();
        $message = Message::query()->with('chat')->find($messageId);
        abort_if(!$message, 404, 'message not found');

        $isMember = ChatMembers::where('chat_id', $message->chat_id)
            ->where('user_id', $user->id)
            ->exists();
        abort_if(!$isMember, 403, 'Not Allowed');

        abort_if($message->sender_id === $user->id, 400, 'cannot mark your own message as read');

        $userSetting = ChatUserSetting::query()->firstOrCreate([
            'user_id' => $user->id,
            'chat_id' => $message->chat_id,
        ]);
        if (!$userSetting->last_read_at || $message->created_at > $userSetting->last_read_at) {
            $userSetting->update(['last_read_at' => $message->created_at]);
        }

        return response()->json([
            'message' => 'message marked as read',
        ], 200);
    }

    // public function markAsReadAll(Request $req)
    // {
    //     $user = User::firstWhere('username', $req->username);
    //     abort_if(!$user, 404, 'user not found');

    //     $chat = $this->directChatForUsers($user, $req->user());
    //     $ok = Message::where('chat_id', $chat->id)
    //         ->where('sender_id', $user->id)
    //         ->whereNull('read_at')
    //         ->update(['read_at' => now()]);

    //     abort_if(!$ok, 400, 'failed to mark message as read');

    //     return response()->json([
    //         'message' => 'message marked as read',
    //     ], 200);
    // }

    // public function destroy(Request $req, string $id)
    // {
    //     $message = Message::findOrFail($id);
    //     abort_unless($message->sender_id === $req->user()->id, 403, 'Unauthorized');
    //     $message->delete();

    //     return response()->json([
    //         'message' => 'message deleted',
    //     ], 200);
    // }

    public function destroy(Request $req, string $messageId)
    {
        $req->validate([
            'delete_for_everyone' => 'sometimes|boolean',
        ]);
        $currentUser = $req->user();
        $message = Message::with('chat')->where('id', $messageId)->first();
        if (!$message) {
            return response()->json(['message' => 'Message not found'], 404);
        }
        $currentUserMemberRow = $message->chat->members()->where('user_id', $currentUser->id)->first();
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $deleteForEveryone = $req->boolean('delete_for_everyone', false);

        // ==========================================
        // SCENARIO A: DELETE FOR EVERYONE
        // ==========================================
        if ($deleteForEveryone) {
            if ($message->chat->type == 'chat') {
                // if chat be a group or channel only admins can delete message
                if ($message->sender_id !== $currentUser->id && $currentUserMemberRow->role == 'normal') {
                    return response()->json(['message' => 'You can only delete your own messages for everyone.'], 403);
                }
            }
            // in direct chat anybody can delete a message !            
            // Global soft delete
            $message->delete();
            return response()->json([
                'message' => 'Message deleted for everyone.',
            ], 200);
        }

        // ==========================================
        // SCENARIO B: DELETE JUST FOR ME
        // ==========================================
        DB::table('message_user_deletions')->updateOrInsert([
            'message_id' => $message->id,
            'user_id' => $currentUser->id,
        ], [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json([
            'message' => 'Message deleted for you.',
        ], 200);
    }
}
