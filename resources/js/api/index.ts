import { apiClient, publicClient } from '@/api/client';
import type { Attachment, Chat, Contact, Message, User } from '@/types';
import type {
    AuthResponse,
    BroadcastAuthResponse,
    LoginCredentials,
    OAuthRedirectResponse,
    Paginated,
    ResourceCollectionPaginated,
    RegisterPayload,
    ApiResponse,
} from '@/types/api';

/* ------------------------------------------------------------------ chats */

export async function getChatList(): Promise<Chat[]> {
    const { data } = await apiClient.get<Chat[]>('/api/chats');
    return data ?? [];
}


export async function getChatBySlug(slug :string): Promise<{ chat: Chat; user: User }> {
    const { data } = await apiClient.get<{ chat: Chat; user: User }>('/api/chats/'+slug);
    return data ?? [];
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

export async function markPrivateMessageRead(messageId: number): Promise<void> {
    await apiClient.put(`/api/messages/${messageId}/read`);
}

// export async function sendDirectMessage(username: string, content: string): Promise<Message> {
//     const { data } = await apiClient.post<{ data: Message }>(`/api/messages/direct/${username}`, {
//         content,
//     });
//     return data.data;
// }

export async function sendDirectMessage(
    username: string,
    content: string,
    attachments: File[] = [],
): Promise<Message> {
    const form = new FormData();
    if (content) {
        form.append('content', content);
    }
    attachments.forEach((file, index) => {
        form.append(`attachments[${index}]`, file);
    });
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

export async function getDirectMessages(
    username: string,
    page?: number,
): Promise<Paginated<Message>> {
    const { data } = await apiClient.get<Paginated<Message>>(`/api/messages/direct/${username}`, {
        params: page ? { page } : undefined,
    });
    return data;
}

/* -------------------------------------------------------- contacts / users */

export async function searchUsers(term: string, page = 1, notInContacts :boolean = true): Promise<Paginated<User>> {
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

export async function getProfilePhotos(username :string, page :number = 1): Promise<ResourceCollectionPaginated<Attachment>> {
    const { data } = await apiClient.get<ResourceCollectionPaginated<Attachment>>(`/api/users/${username}/profiles`,{
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