/**
 * Domain models shared across the app.
 *
 * These mirror the JSON the Laravel API returns. Fields the backend does not
 * always populate are marked optional so a partially-known entity (e.g. a chat
 * the user has navigated to but that is not yet in the loaded list) is valid.
 */

export interface User {
    id?: number;
    name?: string;
    username?: string;
    email?: string;
    profile_photo?: string;
    // profiles?: Object[];
    created_at?: string;
    // Add later
    bio?: string | null;
    last_seen_at?: string | null;
    is_online?: boolean;

    /** Sanctum bearer token; present only on the locally-stored auth user. */
    token?: string;
}

export type ChatType = 'direct' | 'group';

/** A row of the chat list (`GET /api/chats`). */
export interface Chat {
    id?: number;
    slug: string | null;
    name?: string;
    direct_user_id?: number | null;
    type?: ChatType;
    created_at?: string;
    last_message?: string | null;
    last_message_created_at?: string | null;
    unseen_messages_count?: number;
    latest_message_attachments_count?: number;
    profile_photo?: string;

    //Add later
    description?: string | null;
    members_count?: number;
    owner_id?: number | null;
}

export interface Attachment {
    id: number;
    name: string;
    type: string;
    size: number;
    collection: string;
    url: string;
    thumbnail_url: string;
    blurhash?: string;
    expire: string;
    width?: number;
    height?: number;
    created_at: string;
}

export interface Message {
    id?: number;
    chat_id?: number;
    content: string;
    /** Canonical sender id from the DB. */
    sender_id?: number;
    /** Alias the API sets on freshly-created / broadcast messages. */
    from?: number;
    created_at: string;
    updated_at?: string;
    read_at?: string | null;
    chat?: Chat | null; // chat relation of Message
    attachments?: Attachment[];
}

/** Payload of the `NewPrivateMessage` broadcast event. */
export interface IncomingMessage {
    id: number;
    chat_id: number;
    content: string;
    from: number;
    created_at: string;
    updated_at: string;
    read_at: string | null;
    attachments?: Attachment[];
}

/** A day divider injected between messages in the timeline. */
export interface DateSeparator {
    type: 'date';
    label: string;
}

export type ChatTimelineItem = Message | DateSeparator;

export function isDateSeparator(item: ChatTimelineItem): item is DateSeparator {
    return (item as DateSeparator).type === 'date';
}

/** Resolve the sender id regardless of which alias the API used. */
export function messageSenderId(message: Message): number | undefined {
    return message.from ?? message.sender_id;
}

export interface Contact {
    name?: string | null;
    // user: ContactUser;

    // user: User;
    user: Omit<User, 'username'> & {
        username: string;
    };
}
