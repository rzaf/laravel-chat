<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksChatAccess;
use App\Http\Resources\ChatMemberResource;
use App\Models\ChatMembers;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @authenticated
 * @group Chat Members
 *
 * Member management for groups and channels (direct chats have no member management).
 *
 * Rules:
 *  - list    : any member of a group; admins/owner only for channels (subscribers are private).
 *  - add     : admins / owner.
 *  - role    : owner only (admin <-> normal). The owner's role never changes.
 *  - remove  : admins can remove normal members; only the owner can remove admins.
 *              Nobody removes the owner or themselves (use DELETE /chats/{id} to leave).
 */
class ChatMemberController extends Controller
{
    use ChecksChatAccess;

    protected function groupChatOrFail(string $chatId)
    {
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats have no member list');

        return $chat;
    }

    /** Paginated member list: owner first, then admins, then everyone else by name. */
    public function index(Request $req, string $chatId)
    {
        $chat = $this->groupChatOrFail($chatId);
        $me = $this->memberOrFail($req->user()->id, $chat->id);
        abort_if(
            $chat->type === 'channel' && !$this->isAdminRole($me->role),
            403,
            'Only admins can see the subscribers of a channel'
        );

        $members = User::query()
            ->join('chat_members', 'chat_members.user_id', '=', 'users.id')
            ->where('chat_members.chat_id', $chat->id)
            ->whereNull('chat_members.deleted_at')
            ->select('users.*', 'chat_members.role as role')
            ->with('profilePhoto')
            ->orderByRaw("CASE chat_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('users.name')
            ->simplePaginate(50);

        return ChatMemberResource::collection($members);
    }

    /**
     * Add people by username.
     *
     * @bodyParam usernames string[] required Up to 50 usernames.
     */
    public function store(Request $req, string $chatId)
    {
        $chat = $this->groupChatOrFail($chatId);
        $me = $this->memberOrFail($req->user()->id, $chat->id);
        abort_if(!$this->isAdminRole($me->role), 403, 'Only admins can add members');

        $validated = $req->validate([
            'usernames' => 'required|array|min:1|max:50',
            'usernames.*' => 'string|exists:users,username',
        ]);

        $added = [];
        $already = [];
        foreach (User::whereIn('username', $validated['usernames'])->get() as $user) {
            if ($this->addMember($chat, $user->id)) {
                $added[] = $user->username;
            } else {
                $already[] = $user->username;
            }
        }
        if (!empty($added)) {
            try {
                event(new \App\Events\NewChat($chat));
            } catch (\Throwable $th) {
                report($th);
            }
        }

        return response()->json([
            'message' => count($added) . ' member(s) added',
            'added' => $added,
            'already_members' => $already,
        ]);
    }

    /**
     * Promote / demote a member (owner only).
     *
     * @bodyParam role string required admin|normal
     */
    public function update(Request $req, string $chatId, string $userId)
    {
        $chat = $this->groupChatOrFail($chatId);
        $me = $this->memberOrFail($req->user()->id, $chat->id);
        abort_if($me->role !== 'owner', 403, 'Only the owner can change roles');

        $validated = $req->validate(['role' => 'required|in:admin,normal']);

        $target = $this->memberOf((int) $userId, $chat->id);
        abort_if(!$target, 404, 'user is not a member of this chat');
        abort_if($target->role === 'owner', 400, 'The owner role cannot be changed');

        $target->forceFill(['role' => $validated['role']])->save();

        return response()->json([
            'message' => 'Role updated',
            'user_id' => (int) $userId,
            'role' => $target->role,
        ]);
    }

    /** Remove a member from the chat. */
    public function destroy(Request $req, string $chatId, string $userId)
    {
        $chat = $this->groupChatOrFail($chatId);
        $me = $this->memberOrFail($req->user()->id, $chat->id);
        abort_if(!$this->isAdminRole($me->role), 403, 'Only admins can remove members');
        abort_if((int) $userId === $req->user()->id, 400, 'Use "leave" to remove yourself');

        $target = $this->memberOf((int) $userId, $chat->id);
        abort_if(!$target, 404, 'user is not a member of this chat');
        abort_if($target->role === 'owner', 403, 'The owner cannot be removed');
        abort_if(
            $target->role === 'admin' && $me->role !== 'owner',
            403,
            'Only the owner can remove admins'
        );

        $this->removeMember($chat->id, (int) $userId);

        return response()->json(['message' => 'Member removed']);
    }
}
