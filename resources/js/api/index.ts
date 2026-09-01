import { apiClient, publicClient } from '@/api/client';
import type { Chat, Contact, Message, User } from '@/types';
import type {
    AuthResponse,
    BroadcastAuthResponse,
    LoginCredentials,
    OAuthRedirectResponse,
    Paginated,
    RegisterPayload,
} from '@/types/api';

/* ------------------------------------------------------------------ chats */

export async function getChatList(): Promise<Chat[]> {
    const { data } = await apiClient.get<Chat[]>('/api/chats');
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

export async function sendDirectMessage(username: string, content: string): Promise<Message> {
    const { data } = await apiClient.post<{ data: Message }>(`/api/messages/direct/${username}`, {
        content,
    });
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

export async function getContacts(): Promise<Contact[]> {
    const { data } = await apiClient.get<Contact[]>('/api/contacts');
    return data ?? [];
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
