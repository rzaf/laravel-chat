<?php

namespace App\Http\Controllers;

use App\Events\NewChatMessage;
use App\Events\NewPrivateMessage;
use App\Http\Controllers\Concerns\ChecksChatAccess;
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
 * Direct chats are addressed by the other user's username (`/messages/direct/{username}`),
 * groups and channels by the chat slug (`/messages/chats/{slug}`).
 */
class MessageController extends Controller
{
    use ChecksChatAccess;

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

    /* ------------------------------------------------------------------
     | Shared building blocks (used by direct AND group/channel endpoints)
     * ----------------------------------------------------------------- */

    /** Relations every message payload needs. Groups/channels also need the author. */
    protected function messageRelations(Chat $chat): array
    {
        $relations = [
            'media',
            'replyTo.sender',
            'replyTo' => function ($q) {
                $q->withCount('media');
            },
            'forwardedFromMessage.sender.profilePhoto',
            'forwardedFromMessage.chat.profilePhoto',
        ];

        if ($chat->type !== 'direct') {
            $relations[] = 'sender.profilePhoto';
        }

        return $relations;
    }

    /** Paginated (newest first) messages of a chat as seen by the current user. */
    protected function messagesResponse(Request $request, Chat $chat)
    {
        $currentUser = $request->user();

        // Fetch user settings to check if history was cleared
        $settings = ChatUserSetting::where('user_id', $currentUser->id)
            ->where('chat_id', $chat->id)
            ->first();

        $query = Message::query()
            ->with($this->messageRelations($chat))
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

    protected function validateMessage(Request $req): array
    {
        return $req->validate([
            'content' => 'required_without_all:attachments,forwarded_from_message_id|string|max:1024',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:102400|mimes:' . implode(',', static::$mimes),
            'reply_to_id' => 'nullable|exists:messages,id',
            'forwarded_from_message_id' => 'nullable|exists:messages,id',
        ]);
    }

    /**
     * Create the message row (+ forwarded / uploaded media).
     * Must run inside a transaction; permission to post is checked by the caller.
     */
    protected function persistMessage(
        Request $req,
        array $validated,
        Chat $chat,
        User $currentUser,
        MediaService $mediaService,
    ): Message {
        // Ensure the message being replied to lives in this same chat
        if (!empty($validated['reply_to_id'])) {
            $replyMessage = Message::find($validated['reply_to_id']);
            abort_if($replyMessage && $replyMessage->chat_id !== $chat->id, 403, 'Cannot reply to a message from another chat');
        }

        // Determine content: if it's a forward, fetch content directly from the original message model
        $content = $validated['content'] ?? '';
        $forwardedFromMessageId = $validated['forwarded_from_message_id'] ?? null;
        $sourceMessage = null;
        if ($forwardedFromMessageId) {
            $sourceMessage = Message::with(['media', 'chat'])->find($forwardedFromMessageId);
            abort_if(!$sourceMessage || !$sourceMessage->chat, 404, 'Source message to forward not found');

            // You may only forward what you can see: a chat you belong to, or a public group/channel.
            $isMemberOfSource = $this->memberOf($currentUser->id, $sourceMessage->chat_id) !== null;
            abort_if(
                !$isMemberOfSource && $sourceMessage->chat->is_private,
                403,
                'You cannot forward a message from a chat you are not part of'
            );

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

        // Handle linking attachments from the source message if it's a forward
        if ($sourceMessage && $sourceMessage->media->isNotEmpty()) {
            foreach ($sourceMessage->media as $sourceMedia) {
                $mediaService->link($sourceMedia, $message);
            }
        }

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

        return $message;
    }

    /** Load relations, fire the realtime event and build the 201 response. */
    protected function createdResponse(Message $message, Chat $chat, ?User $peer = null)
    {
        $message->load(array_merge(['chat'], $this->messageRelations($chat)));

        if ($chat->type === 'direct' && $peer) {
            $message->chat->name = $message->chat->name ?? $peer->name;
            $message->chat->slug = $message->chat->slug ?? $peer->username;
            $message->chat->direct_user_id = $peer->id;
        }

        try {
            event($chat->type === 'direct'
                ? new NewPrivateMessage($message)
                : new NewChatMessage($message));
        } catch (\Throwable $th) {
            // a broadcasting outage must not fail the request
            report($th);
        }

        return response()->json([
            'message' => 'message created',
            'data' => new MessageResource($message),
        ], 201);
    }

    /* ------------------------------------------------------------------
     | Direct chats  (/messages/direct/{username})
     * ----------------------------------------------------------------- */

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

        return $this->messagesResponse($request, $chat);
    }

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

        $validated = $this->validateMessage($req);

        $result = DB::transaction(function () use ($req, $validated, $currentUser, $user, $mediaService) {
            $chat = $this->directChatForUsers($currentUser, $user);
            $message = $this->persistMessage($req, $validated, $chat, $currentUser, $mediaService);

            return [$chat, $message];
        });
        [$chat, $message] = $result;

        return $this->createdResponse($message, $chat, $user);
    }

    /* ------------------------------------------------------------------
     | Groups & channels  (/messages/chats/{slug})
     * ----------------------------------------------------------------- */

    protected function groupChatBySlug(string $slug): Chat
    {
        return Chat::query()
            ->where('slug', $slug)
            ->whereIn('type', ['group', 'channel'])
            ->firstOrFail();
    }

    /**
     * Messages of a group / channel.
     * Members can always read; non-members only if the chat is public (preview before joining).
     */
    public function getChatMessages(Request $request, string $slug)
    {
        $chat = $this->groupChatBySlug($slug);
        $member = $this->memberOf($request->user()->id, $chat->id);
        abort_if(!$member && $chat->is_private, 403, 'Not Allowed');

        return $this->messagesResponse($request, $chat);
    }

    /**
     * Post to a group / channel.
     * Groups: every member (unless the group is read-only). Channels: admins/owner only.
     *
     * @bodyParam content string optional The message content.
     * @bodyParam attachments[0] file optional First file attachment.
     * @bodyParam reply_to_id integer optional The ID of the message being replied to.
     * @bodyParam forwarded_from_message_id integer optional The ID of the message being forwarded.
     */
    public function storeChatMessage(Request $req, string $slug, MediaService $mediaService)
    {
        $currentUser = $req->user();
        $chat = $this->groupChatBySlug($slug);
        $member = $this->memberOrFail($currentUser->id, $chat->id);
        abort_if(
            !$this->canSendIn($chat, $member),
            403,
            $chat->type === 'channel'
                ? 'Only admins can post in this channel'
                : 'Only admins can send messages in this group'
        );

        $validated = $this->validateMessage($req);

        $message = DB::transaction(
            fn() => $this->persistMessage($req, $validated, $chat, $currentUser, $mediaService)
        );

        return $this->createdResponse($message, $chat);
    }

    /* ------------------------------------------------------------------
     | Edit / read / delete (work for every chat type)
     * ----------------------------------------------------------------- */

    public function update(Request $req, string $id)
    {
        $validated = $req->validate([
            'content' => 'required|string|max:1024',
        ]);

        $messageModel = Message::where('id', $id)
            ->where('sender_id', $req->user()->id)->first();
        abort_if(!$messageModel, 404, 'message not found');

        // someone who left / was removed can't keep editing their old messages
        abort_if(!$this->memberOf($req->user()->id, $messageModel->chat_id), 403, 'Not Allowed');

        abort_if($messageModel->forwarded_from_message_id != null, 404, 'forwarded messages cant be updated');

        $ok = $messageModel->update(['content' => $validated['content']]);
        abort_if(!$ok, 400, 'failed to update');

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
        $currentUserMemberRow = $this->memberOf($currentUser->id, $message->chat_id);
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $deleteForEveryone = $req->boolean('delete_for_everyone', false);

        // ==========================================
        // SCENARIO A: DELETE FOR EVERYONE
        // ==========================================
        if ($deleteForEveryone) {
            if ($message->chat->type !== 'direct') {
                // groups & channels: a normal member can only delete their own messages for everyone,
                // admins / the owner can delete anyone's.
                if ($message->sender_id !== $currentUser->id && !$this->isAdminRole($currentUserMemberRow->role)) {
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
