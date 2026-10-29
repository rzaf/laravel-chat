import { apiClient, publicClient } from '@/api/client';
import type {
    Attachment,
    Chat,
    ChatMember,
    ChatRole,
    ChatType,
    Contact,
    Message,
    MuteDuration,
    PublicChat,
    User,
} from '@/types';
import type {
    AuthResponse,
    BroadcastAuthResponse,
    LoginCredentials,
    OAuthRedirectResponse,
    Paginated,
    ResourceCollectionPaginated,
    RegisterPayload,
    CreateChatPayload,
    ApiResponse,
} from '@/types/api';

/* ------------------------------------------------------------------ chats */

export async function getChatList(): Promise<Chat[]> {
    const { data } = await apiClient.get<Chat[]>('/api/chats');
    return data ?? [];
}


export async function getChatBySlug(slug: string): Promise<{ chat: Chat; user: User }> {
    const { data } = await apiClient.get<{ chat: Chat; user: User }>('/api/chats/' + slug);
    return data ?? [];
}

export async function toggleChatPin(chatId: number, isPinned: boolean): Promise<{ pinned: boolean }> {
    const { data } = await apiClient.patch<{ pinned: boolean }>(`/api/chats/${chatId}/pin`, {
        'is_pinned': isPinned,
    });
    return data;
}

export async function setChatMute(chatId: number, duration: MuteDuration): Promise<{ muted: boolean, muted_until: number | null }> {
    const { data } = await apiClient.patch<{ muted: boolean, muted_until: number | null }>(`/api/chats/${chatId}/mute`, {
        'duration': duration,
    });
    return data;
}

export async function toggleChatArchive(chatId: number, isArchived: boolean): Promise<{ archived: boolean }> {
    const { data } = await apiClient.patch<{ archived: boolean }>(`/api/chats/${chatId}/archive`, {
        'is_archived': isArchived,
    });
    return data;
}

export async function updateChatDraft(chatId: number, draft: string | null): Promise<{ draft: string | null }> {
    const { data } = await apiClient.put<{ draft: string | null }>(`/api/chats/${chatId}/draft`, { draft });
    return data;
}


export async function markChatAsRead(chatId: number): Promise<void> {
    await apiClient.put(`/api/chats/${chatId}/read`);
}

export async function deleteChat(chatId: number, deleteForEveryone?: boolean): Promise<{ message: string }> {
    const { data } = await apiClient.delete<{ message: string }>(`/api/chats/${chatId}`, {
        data: { delete_for_everyone: deleteForEveryone },
    });
    return data;
}

export async function clearChatHistory(chatId: number, deleteForEveryone?: boolean): Promise<{ message: string }> {
    const { data } = await apiClient.delete<{ message: string }>(`/api/chats/${chatId}/clear`, {
        data: { delete_for_everyone: deleteForEveryone },
    });
    return data;
}

/* ---------------------------------------------- groups & channels (chats) */

type ChatResponse = { message: string; chat: Chat | null };

export async function createChat(payload: CreateChatPayload): Promise<Chat> {
    const { data } = await apiClient.post<ChatResponse>('/api/chats', payload);
    return data.chat as Chat;
}

export async function updateChat(
    chatId: number,
    payload: Partial<Pick<Chat, 'name' | 'description' | 'slug' | 'is_private' | 'is_readonly'>>,
): Promise<Chat> {
    const { data } = await apiClient.put<ChatResponse>(`/api/chats/${chatId}`, payload);
    return data.chat as Chat;
}

/** Search public groups / channels (paginated, 20 per page). */
export async function searchPublicChats(q = '', page = 1): Promise<Paginated<PublicChat>> {
    const { data } = await apiClient.get<Paginated<PublicChat>>('/api/chats/public', {
        params: { q: q || undefined, page },
    });
    return data;
}

/** Info about a public chat the user may not have joined yet. */
export async function getPublicChat(slug: string): Promise<PublicChat> {
    const { data } = await apiClient.get<{ chat: PublicChat }>(`/api/chats/public/${slug}`);
    return data.chat;
}

export async function joinChat(chatId: number): Promise<Chat> {
    const { data } = await apiClient.post<ChatResponse>(`/api/chats/${chatId}/join`);
    return data.chat as Chat;
}

export async function joinChatByInvite(inviteCode: string): Promise<Chat> {
    const { data } = await apiClient.post<ChatResponse>(`/api/chats/join/${inviteCode}`);
    return data.chat as Chat;
}

export async function regenerateInviteLink(chatId: number): Promise<string> {
    const { data } = await apiClient.post<{ invite_code: string }>(`/api/chats/${chatId}/invite-link`);
    return data.invite_code;
}

export async function getChatContactMemberUsernames(chatId: number): Promise<string[]> {
    const { data } = await apiClient.get<string[]>(`/api/chats/${chatId}/membersInTheContact`);
    return data ?? [];
}

// export async function getChatMembers(chatId: number, page = 1): Promise<ResourceCollectionPaginated<ChatMember>> {
//     const { data } = await apiClient.get<ResourceCollectionPaginated<ChatMember>>(`/api/chats/${chatId}/members`, {
//         params: page > 1 ? { page } : undefined,
//     });
//     return data;
// }
export async function getChatMembers(chatId: number, page = 1, params?: { role?: string; sort?: string; direction?: string }): Promise<ResourceCollectionPaginated<ChatMember>> {
    const { data } = await apiClient.get<ResourceCollectionPaginated<ChatMember>>(`/api/chats/${chatId}/members`, {
        params: {
            page: page > 1 ? page : undefined,
            role: params?.role && params.role !== 'all' ? params.role : undefined,
            sort: params?.sort,
            direction: params?.direction,
        },
    });
    return data;
}

export async function addChatMembers(
    chatId: number,
    usernames: string[],
): Promise<{ added: string[]; already_members: string[] }> {
    const { data } = await apiClient.post<{ added: string[]; already_members: string[] }>(
        `/api/chats/${chatId}/members`,
        { usernames },
    );
    return data;
}

export async function setChatMemberRole(
    chatId: number,
    userId: number,
    role: Exclude<ChatRole, 'owner'>,
): Promise<void> {
    await apiClient.put(`/api/chats/${chatId}/members/${userId}`, { role });
}

export async function removeChatMember(chatId: number, userId: number): Promise<void> {
    await apiClient.delete(`/api/chats/${chatId}/members/${userId}`);
}

/* ----------------------------------------------------------- broadcasting */

export async function broadcastAuthorize(
    socketId: string,
    channelName: string,
): Promise<BroadcastAuthResponse> {
    const { data } = await apiClient.post<BroadcastAuthResponse>('/api/broadcasting/auth', {
        socket_id: socketId,
        channel_name: channelName,
    });
    return data;
}

/* --------------------------------------------------------------- messages */

export async function markMessageAsRead(messageId: number | string, signal?: AbortSignal) {
    return apiClient.put(`/api/messages/${messageId}/read`, {}, { signal });
}

/** Chats are addressed differently: direct by the peer's username, groups/channels by slug. */
type ChatRef = { slug: string; type?: ChatType };

const messagesUrl = ({ slug, type }: ChatRef): string =>
    type === 'group' || type === 'channel' ? `/api/messages/chats/${slug}` : `/api/messages/direct/${slug}`;

/** Send a message to any kind of chat. */
export async function sendMessage(
    chat: ChatRef,
    content: string,
    attachments: File[] = [],
    replyToId: number | null = null,
    forwardedFromMessageId: number | null = null,
): Promise<Message> {
    const form = new FormData();
    if (content) {
        form.append('content', content);
    }
    attachments.forEach((file, index) => {
        form.append(`attachments[${index}]`, file);
    });
    if (replyToId != null) {
        form.append('reply_to_id', String(replyToId));
    }
    if (forwardedFromMessageId != null) {
        form.append('forwarded_from_message_id', String(forwardedFromMessageId));
    }
    const { data } = await apiClient.post<{ data: Message }>(messagesUrl(chat), form, {
        headers: {
            'Content-Type': 'multipart/form-data',
        },
    });
    return data.data;
}

/** Forward a message into any kind of chat. */
export async function forwardMessageTo(
    chat: ChatRef,
    message: Pick<Message, 'id' | 'content'>,
): Promise<Message> {
    return sendMessage(chat, message.content ?? '', [], null, Number(message.id));
}

/** Newest-first page of messages of any kind of chat. */
export async function getMessages(chat: ChatRef, page?: number): Promise<Paginated<Message>> {
    const { data } = await apiClient.get<Paginated<Message>>(messagesUrl(chat), {
        params: page ? { page } : undefined,
    });
    return data;
}

/** @deprecated direct chats only — prefer `sendMessage` */
export async function sendDirectMessage(
    username: string,
    content: string,
    attachments: File[] = [],
    replyToId: number | null = null,
    forwardedFromMessageId: number | null = null,
): Promise<Message> {
    const form = new FormData();
    if (content) {
        form.append('content', content);
    }
    attachments.forEach((file, index) => {
        form.append(`attachments[${index}]`, file);
    });
    if (replyToId != null) {
        form.append('reply_to_id', String(replyToId));
    }
    if (forwardedFromMessageId != null) {
        form.append('forwarded_from_message_id', String(forwardedFromMessageId));
    }
    const { data } = await apiClient.post<{ data: Message }>(
        `/api/messages/direct/${username}`,
        form,
        {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        },
    );
    return data.data;
}

/**
 * @deprecated direct chats only — prefer `forwardMessageTo`.
 * Forward an existing message to another user's direct chat.
 * The text is sent along with `forwarded_from_message_id` so the backend can
 * mark it as forwarded (and copy attachments, which cannot be re-uploaded here).
 */
export async function forwardMessage(
    username: string,
    message: Pick<Message, 'id' | 'content'>,
): Promise<Message> {
    return sendDirectMessage(username, message.content ?? '', [], null, Number(message.id));
}

/** @deprecated direct chats only — prefer `getMessages` */
export async function getDirectMessages(
    username: string,
    page?: number,
): Promise<Paginated<Message>> {
    const { data } = await apiClient.get<Paginated<Message>>(`/api/messages/direct/${username}`, {
        params: page ? { page } : undefined,
    });
    return data;
}

export async function deleteMessage(messageId: string, deleteForEveryone?: boolean): Promise<{ message: string }> {
    const { data } = await apiClient.delete<{ message: string }>(`/api/messages/${messageId}`, {
        data: { delete_for_everyone: deleteForEveryone },
    });
    return data;
}


export async function updateMessage(messageId: string, newContent: string): Promise<{ message: string }> {
    const { data } = await apiClient.put<{ message: string }>(`/api/messages/${messageId}`, {
        content: newContent
    });
    return data;
}

/* -------------------------------------------------------- contacts / users */

export async function searchUsers(term: string, page = 1, notInContacts: boolean = true): Promise<Paginated<User>> {
    const { data } = await apiClient.get<Paginated<User>>(`/api/users/search/${term}`, {
        params: { page, notInContacts },
    });
    return data;
}

export async function getContacts(): Promise<ApiResponse<Contact[]>> {
    const { data } = await apiClient.get<ApiResponse<Contact[]>>('/api/contacts');
    return data;
}

export async function addOrUpdateContact(username: string, name?: string): Promise<Contact> {
    const { data } = await apiClient.post<{ data: Contact }>(`/api/contacts/users/${username}`, {
        name,
    });
    return data.data;
}

export async function deleteContact(username: string): Promise<void> {
    await apiClient.delete(`/api/contacts/users/${username}`);
}

export async function getProfilePhotos(username: string, page: number = 1): Promise<ResourceCollectionPaginated<Attachment>> {
    const { data } = await apiClient.get<ResourceCollectionPaginated<Attachment>>(`/api/users/${username}/profiles`, {
        params: page ? { page } : undefined,
    });
    return data ?? [];
}

/* --------------------------------------------------------------- auth */

export async function login(credentials: LoginCredentials): Promise<AuthResponse> {
    const { data } = await publicClient.post<AuthResponse>('/api/login', credentials);
    return data;
}

export async function register(payload: RegisterPayload): Promise<AuthResponse> {
    const { data } = await publicClient.post<AuthResponse>('/api/register', payload);
    return data;
}

export async function oauthRedirect(provider: string): Promise<OAuthRedirectResponse> {
    const { data } = await publicClient.post<OAuthRedirectResponse>(`/api/auth/redirect/${provider}`);
    return data;
}

export async function oauthCallback(provider: string, code: string): Promise<AuthResponse> {
    const { data } = await publicClient.post<AuthResponse>(`/api/auth/callback/${provider}`, {
        code,
    });
    return data;
}


/* -------------------------------------------------------- medias */

export async function regenerateMediaUrl(mediaId: string): Promise<Object> {
    const { data } = await apiClient.get<Object>(`/api/medias/${mediaId}/url`);
    return data;
}

export async function fetchMediaBlob(url: string): Promise<string> {
    const response = await apiClient.get(url, { responseType: 'blob' });
    return URL.createObjectURL(response.data);
}