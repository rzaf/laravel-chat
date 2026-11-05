<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksChatAccess;
use App\Http\Resources\MediaResource;
use App\Http\Resources\UserResource;
use App\Models\Chat;
use App\Models\ChatMembers;
use App\Models\ChatUserSetting;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

/**
 * @authenticated
 * @group Chats
 *
 * Chat types:
 *  - direct  : two users, addressed by the peer's username.
 *  - group   : many users, everyone can post (unless is_readonly).
 *  - channel : many users, only admins / the owner can post.
 */
class ChatController extends Controller
{
    use ChecksChatAccess;

    /** Slugs that would collide with fixed routes (`/chats/public`, `/chats/join/...`). */
    protected const RESERVED_SLUGS = 'public,join';

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
            ->whereNull('chats.deleted_at')
            // Join peer (the other member) — DIRECT chats only. Without the type
            // condition a group would produce one row per other member.
            ->leftJoin('chat_members as peer_members', function ($join) use ($userId) {
                $join->on('peer_members.chat_id', '=', 'chat_members.chat_id')
                    ->where('peer_members.user_id', '!=', $userId)
                    ->where('chats.type', '=', 'direct');
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
                        WHERE m.model_type = ? AND m.model_id = peer.id AND m.collection = ? AND m.deleted_at IS NULL
                    )', [User::class, 'profiles']);
            })
            ->leftJoin('medias as chat_media', function ($join) {
                $join->on('chat_media.model_id', '=', 'chats.id')
                    ->where('chat_media.model_type', Chat::class)
                    ->whereRaw('chat_media.id = (
                        SELECT MAX(m.id) FROM medias m
                        WHERE m.model_type = ? AND m.model_id = chats.id AND m.deleted_at IS NULL
                    )', [Chat::class]);
            })
            ->leftJoinSub($latestMessageIds, 'latest_msg_ids', function ($join) {
                $join->on('latest_msg_ids.chat_id', '=', 'chats.id');
            })
            ->leftJoin('messages as latest_msg', 'latest_msg.id', '=', 'latest_msg_ids.id')
            // author of the last message (the list shows "Alice: hi" in groups)
            ->leftJoin('users as latest_sender', 'latest_sender.id', '=', 'latest_msg.sender_id')
            ->select([
                DB::raw('COALESCE(chats.slug, peer.username) as slug'),
                // Priority: Custom Contact Name -> Peer Name -> Chat Name
                DB::raw('COALESCE(user_contacts.name, chats.name, peer.name) as name'),
                'peer.id as direct_user_id',
                'chats.type',
                'chats.id as id',
                'chats.created_at',
                // group / channel info
                'chats.description',
                'chats.is_private',
                'chats.is_readonly',
                'chats.invite_code',
                'chats.creator as owner_id',
                'chat_members.role as my_role',
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
                        ELSE latest_msg.type 
                    END as last_message_type'),
                DB::raw('
                    CASE 
                        WHEN chat_user_settings.cleared_at IS NOT NULL AND latest_msg.created_at <= chat_user_settings.cleared_at 
                        THEN NULL 
                        ELSE latest_msg.created_at 
                    END as last_message_created_at'),
                'latest_msg.sender_id as last_message_sender_id',
                'latest_sender.name as last_message_sender_name',
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
                'chat_user_settings.muted_until',
                'chat_user_settings.draft',
                'chat_user_settings.last_read_at AS last_read_at',
            ])
            ->selectSub(function ($query) {
                $query->from('chat_members as cm')
                    ->whereColumn('cm.chat_id', 'chats.id')
                    ->whereNull('cm.deleted_at')
                    ->selectRaw('COUNT(*)');
            }, 'members_count')
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
        $isDirect = $chat->type === 'direct';

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

        // Group / channel properties
        $chat->is_private = (bool) $chat->is_private;
        $chat->is_readonly = (bool) $chat->is_readonly;
        $chat->members_count = $isDirect ? null : (int) $chat->members_count;
        $chat->can_send = $isDirect
            || $this->isAdminRole($chat->my_role)
            || ($chat->type === 'group' && !$chat->is_readonly);
        if ($isDirect) {
            $chat->owner_id = null;
            $chat->description = null;
            $chat->last_message_sender_name = null;
        }
        // only admins may see / share the invite link
        if (!$this->isAdminRole($chat->my_role)) {
            unset($chat->invite_code);
        }

        $chat->last_message_is_mine = $chat->last_message_sender_id == $currentUserId;

        if ($chat->last_message_is_mine) {
            $chat->last_message_status = $chat->last_message_read_at ? 'seen' : 'delivered';
        } else {
            $chat->last_message_status = 'seen';
        }

        unset($chat->last_message_sender_id, $chat->last_message_read_at);

        return $chat;
    }

    /** One formatted chat row for the given user (or null if they are not a member). */
    protected function findFormattedChat(int $userId, int $chatId)
    {
        $chat = $this->baseChatQuery($userId)->where('chats.id', $chatId)->first();

        return $chat ? $this->formatChat($chat) : null;
    }

    public function index(Request $req)
    {
        $userId = $req->user()->id;

        return $this->baseChatQuery($userId)
            ->orderByDesc(DB::raw('COALESCE(latest_msg.created_at, chats.created_at)'))
            ->get()
            ->map(fn($chat) => $this->formatChat($chat));
    }


    /**
     * get chat
     * 
     * @urlParam slug string Example: test
     * 
     */
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

        // only direct chats have a "peer" user to describe
        $user = (($chat->type ?? 'direct') != 'direct') ? null : User::where('username', $slug)->first();

        return response()->json([
            'chat' => $chat,
            'user' => $user ? new UserResource($user) : null,
        ], 200);
    }

    /* ------------------------------------------------------------------
     | Create / edit groups & channels
     * ----------------------------------------------------------------- */

    protected function generateSlug(string $name): string
    {
        do {
            $slug = (Str::slug($name) ?: 'chat') . '-' . Str::lower(Str::random(10));
        } while (
            Chat::withTrashed()->where('slug', $slug)->exists()
            || User::where('username', $slug)->exists()
        );

        return $slug;
    }

    /**
     * Create a group or a channel. The creator becomes its owner.
     *
     * @bodyParam type string required group|channel
     * @bodyParam name string required
     * @bodyParam description string optional
     * @bodyParam slug string optional Public link name; generated when omitted.
     * @bodyParam is_private boolean optional Private chats can only be joined with the invite link.
     * @bodyParam is_readonly boolean optional Groups only: only admins can send.
     * @bodyParam members string[] optional Usernames to add right away.
     * 
     */
    public function store(Request $req)
    {
        $user = $req->user();
        $validated = $req->validate([
            'type' => 'required|in:group,channel',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:512',
            'slug' => [
                'nullable',
                'string',
                'min:3',
                'max:127',
                'regex:/^[A-Za-z0-9_-]+$/',
                'not_in:' . self::RESERVED_SLUGS,
                'unique:chats,slug',
                'unique:users,username',
            ],
            'is_private' => 'sometimes|boolean',
            'is_readonly' => 'sometimes|boolean',
            'members' => 'sometimes|array|max:50',
            'members.*' => 'string|exists:users,username',
        ]);

        $chat = DB::transaction(function () use ($validated, $user, $req) {
            $chat = new Chat();
            $chat->forceFill([
                'creator' => $user->id,
                'type' => $validated['type'],
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? $this->generateSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'is_private' => $req->boolean('is_private', false),
                // channels are always read-only for normal members
                'is_readonly' => $validated['type'] === 'channel' ? true : $req->boolean('is_readonly', false),
                'invite_code' => Str::random(24),
            ])->save();

            $this->addMember($chat, $user->id, 'owner');

            $memberIds = User::whereIn('username', $validated['members'] ?? [])
                ->where('id', '!=', $user->id)
                ->pluck('id');
            foreach ($memberIds as $memberId) {
                $this->addMember($chat, $memberId, 'normal');
            }

            return $chat;
        });

        try {
            event(new \App\Events\NewChat($chat));
        } catch (\Throwable $th) {
            report($th);
        }

        return response()->json([
            'message' => ucfirst($chat->type) . ' created',
            'chat' => $this->findFormattedChat($user->id, $chat->id),
        ], 201);
    }

    /**
     * Edit a group / channel.
     * Admins: name, description. Owner only: slug, is_private, is_readonly.
     */
    public function update(Request $req, string $chatId)
    {
        $user = $req->user();
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats cannot be edited');
        $member = $this->memberOrFail($user->id, $chat->id);
        abort_if(!$this->isAdminRole($member->role), 403, 'Only admins can edit this chat');

        $validated = $req->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:512',
            'slug' => [
                'sometimes',
                'string',
                'min:3',
                'max:127',
                'regex:/^[A-Za-z0-9_-]+$/',
                'not_in:' . self::RESERVED_SLUGS,
                Rule::unique('chats', 'slug')->ignore($chat->id),
                'unique:users,username',
            ],
            'is_private' => 'sometimes|boolean',
            'is_readonly' => 'sometimes|boolean',
        ]);

        $ownerOnly = array_intersect(array_keys($validated), ['slug', 'is_private', 'is_readonly']);
        abort_if($ownerOnly && $member->role !== 'owner', 403, 'Only the owner can change the link or privacy');

        if ($chat->type === 'channel') {
            unset($validated['is_readonly']); // channels stay read-only for normal members
        }

        // `boolean` accepts "1"/"true"/"on"...; store real booleans
        foreach (['is_private', 'is_readonly'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $validated[$flag] = $req->boolean($flag);
            }
        }

        $chat->forceFill($validated)->save();

        return response()->json([
            'message' => 'Chat updated',
            'chat' => $this->findFormattedChat($user->id, $chat->id),
        ]);
    }

    /** Admins: issue a new invite link (the old one stops working). */
    public function regenerateInvite(Request $req, string $chatId)
    {
        $user = $req->user();
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats have no invite link');
        $member = $this->memberOrFail($user->id, $chat->id);
        abort_if(!$this->isAdminRole($member->role), 403, 'Only admins can manage the invite link');

        $chat->forceFill(['invite_code' => Str::random(24)])->save();

        return response()->json([
            'message' => 'Invite link regenerated',
            'invite_code' => $chat->invite_code,
        ]);
    }

    /* ------------------------------------------------------------------
     | Discover / join
     * ----------------------------------------------------------------- */

    protected function publicChatQuery(int $userId)
    {
        return Chat::query()
            ->whereIn('chats.type', ['group', 'channel'])
            ->where('chats.is_private', false)
            ->leftJoin('medias as chat_media', function ($join) {
                $join->on('chat_media.model_id', '=', 'chats.id')
                    ->where('chat_media.model_type', Chat::class)
                    ->whereRaw('chat_media.id = (
                        SELECT MAX(m.id) FROM medias m
                        WHERE m.model_type = ? AND m.model_id = chats.id AND m.deleted_at IS NULL
                    )', [Chat::class]);
            })
            ->select([
                'chats.id',
                'chats.name',
                'chats.slug',
                'chats.type',
                'chats.description',
                'chats.created_at',
                'chat_media.id as profile_id',
            ])
            ->selectSub(function ($q) {
                $q->from('chat_members as cm')
                    ->whereColumn('cm.chat_id', 'chats.id')
                    ->whereNull('cm.deleted_at')
                    ->selectRaw('COUNT(*)');
            }, 'members_count')
            ->selectSub(function ($q) use ($userId) {
                $q->from('chat_members as me')
                    ->whereColumn('me.chat_id', 'chats.id')
                    ->where('me.user_id', $userId)
                    ->whereNull('me.deleted_at')
                    ->selectRaw('COUNT(*)');
            }, 'is_member');
    }

    protected function formatPublicChat($chat)
    {
        $chat->profile_photo = $chat->profile_id
            ? route('media.public', ['id' => $chat->profile_id, 'thumbnail' => 1])
            : null;
        unset($chat->profile_id);
        $chat->members_count = (int) $chat->members_count;
        $chat->is_member = (bool) $chat->is_member;

        return $chat;
    }

    /**
     * Search public groups / channels.
     *
     * @queryParam q string optional Matches name or slug.
     */
    public function discover(Request $req)
    {
        $req->validate(['q' => 'nullable|string|max:100']);
        $term = trim((string) $req->query('q', ''));

        $page = $this->publicChatQuery($req->user()->id)
            ->when($term !== '', function ($query) use ($term) {
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('chats.name', 'like', $like)
                        ->orWhere('chats.slug', 'like', $like);
                });
            })
            ->orderByDesc('chats.created_at')
            ->simplePaginate(20);

        $page->getCollection()->transform(fn($chat) => $this->formatPublicChat($chat));

        return $page;
    }

    /** Info about a public group / channel (also for people who haven't joined yet). */
    public function preview(Request $req, string $slug)
    {
        $chat = $this->publicChatQuery($req->user()->id)
            ->where('chats.slug', $slug)
            ->first();
        abort_if(!$chat, 404, 'chat not found');

        return response()->json(['chat' => $this->formatPublicChat($chat)]);
    }

    /** Join a public group / channel. Idempotent. */
    public function join(Request $req, string $chatId)
    {
        $user = $req->user();
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats cannot be joined');
        abort_if($chat->is_private, 403, 'This chat is private, you need an invite link');

        $joined = $this->addMember($chat, $user->id);

        return response()->json([
            'message' => $joined ? 'You joined the ' . $chat->type : 'You are already a member',
            'chat' => $this->findFormattedChat($user->id, $chat->id),
        ]);
    }

    /** Join a (private or public) group / channel with its invite link. Idempotent. */
    public function joinByInvite(Request $req, string $inviteCode)
    {
        $user = $req->user();
        $chat = Chat::query()
            ->where('invite_code', $inviteCode)
            ->whereIn('type', ['group', 'channel'])
            ->first();
        abort_if(!$chat, 404, 'invite link is invalid or expired');

        $joined = $this->addMember($chat, $user->id);

        return response()->json([
            'message' => $joined ? 'You joined the ' . $chat->type : 'You are already a member',
            'chat' => $this->findFormattedChat($user->id, $chat->id),
        ]);
    }

    /* ------------------------------------------------------------------
     | Delete / leave / clear
     * ----------------------------------------------------------------- */

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
        $currentUserMemberRow = $this->memberOf($currentUser->id, $chat->id);
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'You are not a member of this chat'], 403);
        }
        $deleteForEveryone = $req->boolean('delete_for_everyone', false);
        $isGroupLike = $chat->type !== 'direct';

        // ==========================================
        // SCENARIO A: DELETE / DESTROY FOR EVERYONE
        // ==========================================
        if ($deleteForEveryone) {
            // groups & channels can only be destroyed by their owner
            if ($isGroupLike && $currentUserMemberRow->role !== 'owner') {
                return response()->json(['message' => 'Only the owner can delete a ' . $chat->type . ' for everyone.'], 403);
            }
            // Soft delete all messages in the chat globally
            $chat->messages()->delete();
            // Soft delete the chat globally
            $chat->delete();
            // delete chat members so it doesn't appear in anyone's chat list
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
        if ($isGroupLike) {
            if ($currentUserMemberRow->role === 'owner') {
                return response()->json(['message' => 'The owner cant leave. Delete the ' . $chat->type . ' instead.'], 403);
            }
            // For groups & channels, "deleting for me" means leaving entirely
            $this->removeMember($chat->id, $currentUser->id);
            return response()->json([
                'message' => 'You have left the ' . $chat->type . '.',
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
        $this->removeMember($chat->id, $currentUser->id);

        return response()->json([
            'message' => 'Chat removed from your list and history cleared.',
        ], 200);
    }



    /** Paginated attachments of a chat, filtered by attachment MIME type or message type. */
    protected function mediaResponse(Request $request, Chat $chat, string $type)
    {
        $types = ['photos', 'videos', 'audios', 'files', 'audio-messages', 'video-messages'];
        abort_unless(in_array($type, $types, true), 404, 'Invalid media type');

        $currentUser = $request->user();
        $settings = ChatUserSetting::where('user_id', $currentUser->id)
            ->where('chat_id', $chat->id)
            ->first();

        $query = Media::query()
            ->join('messages', function ($join) {
                $join->on('messages.id', '=', 'medias.model_id')
                    ->where('medias.model_type', Message::class);
            })
            ->where('messages.chat_id', $chat->id)
            ->whereNull('messages.deleted_at')
            ->where('medias.collection', 'attachments')
            // Exclude messages deleted individually by this user ("Delete for Me").
            ->whereNotExists(function ($subQuery) use ($currentUser) {
                $subQuery->select(DB::raw(1))
                    ->from('message_user_deletions')
                    ->whereColumn('message_user_deletions.message_id', 'messages.id')
                    ->where('message_user_deletions.user_id', $currentUser->id);
            })
            ->select('medias.*');

        // Respect the user's cleared chat history, just like the messages endpoint.
        if ($settings && $settings->cleared_at) {
            $query->where('messages.created_at', '>', $settings->cleared_at);
        }

        switch ($type) {
            case 'photos':
                $query->where('messages.type', 'normal')
                    ->where('medias.mime_type', 'like', 'image/%');
                break;
            case 'videos':
                $query->where('messages.type', 'normal')
                    ->where('medias.mime_type', 'like', 'video/%');
                break;
            case 'audios':
                $query->where('messages.type', 'normal')
                    ->where('medias.mime_type', 'like', 'audio/%');
                break;
            case 'files':
                // Documents and other attachments that are not images, videos, or audio files.
                $query->where('messages.type', 'normal')
                    ->where(function ($q) {
                        $q->whereNull('medias.mime_type')
                            ->orWhere(function ($q) {
                                $q->where('medias.mime_type', 'not like', 'image/%')
                                    ->where('medias.mime_type', 'not like', 'video/%')
                                    ->where('medias.mime_type', 'not like', 'audio/%');
                            });
                    });
                break;
            case 'audio-messages':
                $query->where('messages.type', 'audio');
                break;
            case 'video-messages':
                $query->where('messages.type', 'video');
                break;
        }

        return MediaResource::collection(
            $query->orderByDesc('messages.created_at')
                ->orderByDesc('messages.id')
                ->orderByDesc('medias.id')
                ->simplePaginate(20)
        );
    }

    /** Attachments and media messages in a direct chat, group, or channel. 
     * 
     * @urlParam slug string chat slug.
     * @queryParam type required The media category. Enum: photos,videos,audios,files,audio-messages,video-messages Example: photos
     */
    public function getChatMedia(Request $request, string $slug)
    {
        $currentUser = $request->user();
        // Query parameters
        \Validator::make($request->query(), [
            'type' => 'required|in:photos,videos,audios,files,audio-messages,video-messages',
        ])->validate();
        $type = $request->type;
        // Group/channel slugs and usernames share the same identifier space.
        $chat = Chat::query()
            ->where('slug', $slug)
            ->whereIn('type', ['group', 'channel'])
            ->first();

        if ($chat) {
            $member = $this->memberOf($currentUser->id, $chat->id);
            abort_if(!$member && $chat->is_private, 403, 'Not Allowed');

            return $this->mediaResponse($request, $chat, $type);
        }

        // If it is not a group/channel slug, treat it as a direct-chat username.
        $otherUser = User::where('username', $slug)->firstOrFail();
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
            return MediaResource::collection(
                Media::query()->whereRaw('1 = 0')->simplePaginate(20)
            );
        }

        return $this->mediaResponse($request, $chat, $type);
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
        $currentUserMemberRow = $this->memberOf($currentUser->id, $chat->id);
        if (!$currentUserMemberRow) {
            return response()->json(['message' => 'You are not a member of this chat'], 403);
        }
        if ($chat->type !== 'direct') {
            return response()->json(['message' => 'Cant clear history of groups or channels'], 403);
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

    /* ------------------------------------------------------------------
     | Per-user chat settings (work for every chat type)
     * ----------------------------------------------------------------- */

    /**
     * Toggle or set pin status for a chat.
     */
    public function togglePin(Request $req, string $chatId)
    {
        $userId = $req->user()->id;
        $req->validate([
            'is_pinned' => 'required|boolean',
        ]);
        $chat = $this->findChatOrFail($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = ChatUserSetting::updateOrCreate(
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
        $chat = $this->findChatOrFail($chatId);
        $this->verifyChatMembership($userId, $chat->id);
        $mutedUntil = null;
        if ($req->duration && $req->duration != 'forever') {
            $mutedUntil = now()->addMinutes(self::$muteDurations[$req->duration]);
        }

        $setting = ChatUserSetting::updateOrCreate(
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
        $chat = $this->findChatOrFail($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = ChatUserSetting::updateOrCreate(
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
        $chat = $this->findChatOrFail($chatId);
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
            // nullable: sending null clears the draft
            'draft' => 'nullable|string|max:1024',
        ]);
        $chat = $this->findChatOrFail($chatId);
        $this->verifyChatMembership($userId, $chat->id);

        $setting = ChatUserSetting::updateOrCreate(
            ['user_id' => $userId, 'chat_id' => $chat->id],
            ['draft' => $validated['draft'] ?? null]
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
        $this->memberOrFail($userId, $chatId);
    }



    /**
     * get chat profiles (gallery)
     */
    public function getProfiles(Request $req, string $slug)
    {
        $userId = $req->user()->id;
        $chat = Chat::query()->where('slug', $slug)->first();
        abort_if(!$chat, 404, 'Chat not found');
        $this->verifyChatMembership($userId, $chat->id);

        $medias = Media::query()
            ->where('model_id', $chat->id)
            ->where('model_type', Chat::class)
            ->where('collection', 'profiles')
            ->latest()
            ->paginate(8);

        return MediaResource::collection($medias);
    }

    /**
     * upload a new chat profile photo (Admin / Owner only)
     */
    public function uploadProfile(Request $req, string $chatId, \App\Services\MediaService $mediaService)
    {
        $userId = $req->user()->id;
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats use user profiles instead');

        $member = $this->memberOrFail($userId, $chat->id);
        abort_if(!$this->isAdminRole($member->role), 403, 'Only admins or the owner can update chat profile photos');

        $validated = $req->validate([
            'photo' => 'required|file|max:102400|mimes:jpg,jpeg,png',
        ]);

        try {
            DB::beginTransaction();
            $file = $req->file('photo');
            $mediaService->upload(
                model: $chat,
                file: $file,
                collection: 'profiles',
                disk: 'private'
            );
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            abort(500, $th->getMessage());
        }

        $media = Media::query()
            ->where('model_id', $chat->id)
            ->where('model_type', Chat::class)
            ->where('collection', 'profiles')
            ->latest()->first();
        return response()->json([
            'message' => 'Profile photo uploaded successfully',
            'profile' => new MediaResource($media),
        ]);
    }

    /**
     * delete a chat profile photo (Admin / Owner only)
     */
    public function deleteProfile(Request $req, string $chatId, string $id)
    {
        $userId = $req->user()->id;
        $chat = $this->findChatOrFail($chatId);
        abort_if($chat->type === 'direct', 400, 'Direct chats use user profiles instead');

        $member = $this->memberOrFail($userId, $chat->id);
        abort_if(!$this->isAdminRole($member->role), 403, 'Only admins or the owner can delete chat profile photos');

        $media = Media::where('id', $id)
            ->where('model_id', $chat->id)
            ->where('model_type', Chat::class)
            ->where('collection', 'profiles')
            ->first();

        if (!$media) {
            return response()->json([
                'message' => 'Profile photo not found!',
            ], 404);
        }

        $media->delete();

        $media = Media::query()
            ->where('model_id', $chat->id)
            ->where('model_type', Chat::class)
            ->where('collection', 'profiles')
            ->latest()->first();
        return response()->json([
            'message' => 'Profile photo deleted successfully',
            'profile' => new MediaResource($media),
        ]);
    }
}
