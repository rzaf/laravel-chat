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
    phone?: string;
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

export type ChatType = 'direct' | 'group' | 'channel';

/** Role inside a group / channel (`chat_members.role`). */
export type ChatRole = 'owner' | 'admin' | 'normal';

/** A row of the chat list (`GET /api/chats`). */
export interface Chat {
    id?: number;
    slug: string | null;
    name?: string;
    direct_user_id?: number | null;
    type?: ChatType;
    created_at?: string;
    last_message?: string | null;
    last_message_type?: MessageType | null;
    last_message_created_at?: string | null;
    last_read_at?: string | null;
    unseen_messages_count?: number;
    latest_message_attachments_count?: number;
    profile_photo?: string;

    description?: string | null;
    members_count?: number | null;
    owner_id?: number | null;

    // --- groups & channels ---
    is_private?: boolean;
    /** Only admins can send (always true for channels). */
    is_readonly?: boolean;
    /** The current user's role in this chat. */
    my_role?: ChatRole | null;
    /** Whether the current user may post here (computed by the backend). */
    can_send?: boolean;
    /** Only present for admins / the owner. */
    invite_code?: string;
    /** Author of the last message, for the "Alice: hi" preview in groups. */
    last_message_sender_name?: string | null;
    /**
     * `false` for a public chat the user is only previewing (not joined yet).
     * Missing / `true` for every chat that comes from the chat list.
     */
    is_member?: boolean;
    // is_online: boolean;
    // is_pinned: boolean;

    pinned: boolean;
    muted: boolean;
    mutedFor: MuteDuration;
    muted_until: number | null;
    archived: boolean;
    is_online: boolean;
    is_typing: boolean;
    draft: string | null;
    last_message_is_mine: boolean;
    last_message_status: 'sent' | 'delivered' | 'seen';
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
    duration?: number;
    created_at: string;
}

export type MessageType = 'normal' | 'audio' | 'video';

export interface Message {
    id?: number;
    chat_id?: number;
    content: string;
    type: MessageType;
    sender_id?: number;
    sender?: User;
    created_at: string;
    updated_at?: string;
    chat?: Chat | null; // chat relation of Message
    attachments?: Attachment[];
    attachments_count?: number;
    reply_to_id?: number;
    reply_to?: Message;
    forwarded_from_message_id?: number;
    forwarded_from?: Chat;
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

/** A message prepared for rendering in the chat timeline. */
export interface MessageRow {
    id?: string;
    key: string | number;
    text: string;
    type: MessageType;
    time: string;
    updatedAt?: string;
    forwardedFrom?: Chat;
    isSent: boolean;
    /** Author — shown above the text in group chats. */
    senderName?: string;
    senderProfile?: string;
    senderSlug?: string;
    senderId?: number;
    attachments?: Attachment[];
    attachments_count?: number;
}

/** Messages under one date divider (label is empty for the leading group). */
export interface MessageGroup {
    key: string;
    label: string;
    messages: MessageRow[];
}

export interface Contact {
    name?: string | null;
    // user: ContactUser;

    // user: User;
    user: Omit<User, 'username'> & {
        username: string;
    };
}


export type MuteDuration = '15m' | '1h' | '8h' | '24h' | '1w' | 'forever' | null;

/** A user as a member of a group / channel (`GET /api/chats/{id}/members`). */
export interface ChatMember extends User {
    role: ChatRole;
}

/** Row of the public group / channel search (`GET /api/chats/public`). */
export interface PublicChat {
    id: number;
    slug: string;
    name: string;
    type: 'group' | 'channel';
    description: string | null;
    members_count: number;
    is_member: boolean;
    profile_photo: string | null;
    created_at: string;
}
