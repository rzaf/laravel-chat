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

        $validated = $req->validate([
            'role' => 'sometimes|in:all,admins,normal',
            'sort' => 'sometimes|in:name,joined_at,role',
            'direction' => 'sometimes|in:asc,desc'
        ]);
        $role = $validated['role'] ?? 'all';
        $sort = $validated['sort'] ?? 'role';
        $direction = $validated['direction'] ?? 'asc';


        $members = User::query()
            ->join('chat_members', 'chat_members.user_id', '=', 'users.id')
            ->where('chat_members.chat_id', $chat->id)
            ->whereNull('chat_members.deleted_at')
            ->when($this->isAdminRole($me->role) && $role === 'admins', fn($q) => $q->whereIn('chat_members.role', ['owner', 'admin']))
            ->when($this->isAdminRole($me->role) && $role === 'normal', fn($q) => $q->where('chat_members.role', 'normal'))
            ->select('users.*', 'chat_members.role as role')
            ->with('profilePhoto')
            // ->orderByRaw("CASE chat_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            // ->orderBy('users.name')
            ->when($sort === 'role', fn($q) => $q->orderByRaw("CASE chat_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END " . ($direction === 'desc' ? 'DESC' : 'ASC')))
            ->when($sort === 'name', fn($q) => $q->orderBy('users.name', $direction))
            ->when($sort === 'joined_at', fn($q) => $q->orderBy('chat_members.created_at', $direction))
            ->orderBy('users.id')
            ->paginate(10);

        return ChatMemberResource::collection($members);
    }

    public function membersInTheContact(Request $req, string $chatId)
    {
        $chat = $this->groupChatOrFail($chatId);

        $this->memberOrFail($req->user()->id, $chat->id);

        $contactUserIds = $req->user()->contacts()
            ->pluck('user_id');

        $usernames = User::query()
            ->join('chat_members', 'chat_members.user_id', '=', 'users.id')
            ->where('chat_members.chat_id', $chat->id)
            ->whereNull('chat_members.deleted_at')
            ->whereIn('users.id', $contactUserIds)
            ->pluck('users.username');

        return response()->json($usernames);
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
