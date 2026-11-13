import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getChatBySlug, getChatList } from '@/api';
import type { Chat, ChatType, Message, MessageType } from '@/types';
import useAuthStore from '@/stores/auth';

function timestamp(value: string | null | undefined): number {
    return value ? new Date(value).getTime() : 0;
}
export default defineStore('chat', () => {
    const auth = useAuthStore();
    /** `null` while the initial list is still loading. */
    const chatList = ref<Chat[] | null>(null);
    const chatListLoadPromise = ref<Promise<void> | null>(null);
    const currentChat = ref<Chat | null>(null);
    /** Signals the open chat window to append a just-received message. */
    const lastIncoming = ref<Message | null>(null);

    const isLoading = computed(() => chatList.value === null);

    function findChat(chatId: number | undefined): Chat | undefined {
        if (chatId == null) {
            return undefined;
        }
        return chatList.value?.find((c) => c.id === chatId);
    }

    function reorder(): void {
        chatList.value?.sort(
            // chats without messages (e.g. a group you just created) sort by creation time
            (a, b) =>
                timestamp(b.last_message_created_at ?? b.created_at) -
                timestamp(a.last_message_created_at ?? a.created_at),
        );
    }

    async function loadChatList(): Promise<void> {
        if (chatListLoadPromise.value) {
            return chatListLoadPromise.value;
        }

        chatListLoadPromise.value = (async () => {
            try {
                chatList.value = await getChatList();
            } finally {
                chatListLoadPromise.value = null;
            }
        })();

        return chatListLoadPromise.value;
    }

    /** Guards against out-of-order results when the user switches chats quickly. */
    let selectToken = 0;

    /**
     * Point `currentChat` at the chat for `slug`.
     *
     * Not in the list means one of two things:
     *  - a public group / channel the user hasn't joined yet -> a preview (`is_member: false`)
     *  - a brand-new direct chat (slug = username)           -> a bare placeholder
     */
    async function selectChatBySlug(slug: string): Promise<void> {
        const token = ++selectToken;

        if (chatList.value === null) {
            await loadChatList();
        }

        const known = chatList.value?.find((c) => c.slug === slug);
        if (known) {
            if (token === selectToken) currentChat.value = known;
            return;
        }

        let resolved = { slug } as Chat;
        try {
            const res = await getChatBySlug(slug);
            console.log('chat store selectChatBySlug request getChatBySlug res: ', res);

            if (res.chat?.is_member) {
                // joined elsewhere (another tab / device): the list is just stale
                await loadChatList();
                resolved = chatList.value?.find((c) => c.slug === slug) ?? resolved;
            } else {
                if (res.chat == null && res.user != null) { // a user that we are not in chat with
                    resolved = {
                        slug: res.user?.username,
                        name: res.user?.name,
                        type: 'direct',
                        description: res.user?.bio,
                        profile_photo: res.user?.profile_photo ?? undefined,
                        is_member: true,
                    } as Chat;
                } else if (res.chat != null) { // 
                    resolved = {
                        id: res.chat.id,
                        slug: res.chat.slug,
                        name: res.chat.name,
                        type: res.chat.type,
                        description: res.chat.description,
                        members_count: res.chat.members_count,
                        profile_photo: res.chat.profile_photo ?? undefined,
                        is_member: false,
                        can_send: false,
                    } as Chat;
                } else {
                    resolved = {
                        id: res.publicChat?.id,
                        slug: res.publicChat?.slug,
                        name: res.publicChat?.name,
                        type: res.publicChat?.type,
                        description: res.publicChat?.description,
                        members_count: res.publicChat?.members_count,
                        profile_photo: res.publicChat?.profile_photo ?? undefined,
                        is_member: res.publicChat?.is_member ?? undefined,
                        can_send: false,
                    } as Chat;
                }
            }
        } catch {
            // 404 -> not a public chat: treat as a direct-chat placeholder
        }

        if (token === selectToken) currentChat.value = resolved;
    }

    /**
     * Insert or update a chat coming from the API (create / join responses).
     * Returns the reactive object that lives in the list.
     */
    function upsertChat(chat: Chat): Chat {
        if (chatList.value === null) {
            void loadChatList();
            return chat;
        }
        const index = chatList.value.findIndex((c) => c.id === chat.id);
        if (index === -1) {
            chatList.value.push(chat);
        } else {
            Object.assign(chatList.value[index], chat);
        }
        reorder();
        const stored = chatList.value.find((c) => c.id === chat.id) as Chat;
        if (currentChat.value?.slug === stored.slug) {
            currentChat.value = stored; // e.g. a "Join" just turned the preview into a real chat
        }
        return stored;
    }

    function clearCurrentChat(): void {
        currentChat.value = null;
    }

    function removeChat(chatId: number): void {
        if (chatList.value != null && chatList.value.length > 0) {
            const index = chatList.value.findIndex((c) => c.id == chatId);
            if (index !== -1) {
                chatList.value.splice(index, 1);
            }
        }
    }

    function setLastMessage(newMessage: Message): void {
        const chat = findChat(newMessage.chat_id);
        if (chat) {
            chat.last_message = newMessage.content;
            chat.latest_message_attachments_count = newMessage.attachments?.length;
            chat.last_message_type = newMessage.type || 'normal';
            chat.last_message_created_at = newMessage.created_at;
            chat.last_message_is_mine = true;
            reorder();
        }
    }

    function addChat(
        id: number,
        slug: string,
        name: string,
        lastMessage: string,
        directUserId: number | null = null,
        type: ChatType = 'direct',
        lastMessageType: MessageType = 'normal',
        profilePhoto: string | null,
    ) {
        if (!chatList.value) {
            return;
        }
        // Avoid duplicates
        if (findChat(id)) {
            return;
        }
        const now = new Date().toISOString();
        chatList.value.push(<Chat>{
            id: id,
            slug,
            name: name,
            last_message: lastMessage,
            last_message_type: lastMessageType,
            direct_user_id: directUserId,
            last_message_created_at: now,
            created_at: now,
            unseen_messages_count: 0,
            type,
            profile_photo: profilePhoto,
        });
        reorder();
    }

    function decrementUnseen(chatId: number): void {
        const chat = findChat(chatId);
        if (chat && chat.unseen_messages_count) {
            chat.unseen_messages_count -= 1;
        }
    }

    function seenAll(chatId: number): void {
        const chat = findChat(chatId);
        if (chat) {
            chat.unseen_messages_count = 0;
            chat.last_read_at = chat.last_message_created_at ?? new Date().toISOString();
        }
    }


    /** Advance last_read_at (never backwards) and set the badge count. */
    function setReadState(chatId: number, readAt: string, unseen: number): void {
        const chat = findChat(chatId);
        if (!chat) return;
        if (timestamp(readAt) > timestamp(chat.last_read_at)) {
            chat.last_read_at = readAt;
        }
        chat.unseen_messages_count = Math.max(0, unseen);
    }

    /** Apply a realtime message to the list, then signal the open window. */
    function receiveMessage(message: Message): void {
        const chat = findChat(message.chat_id);
        if (message.attachments == null) {
            message.attachments = [];
            message.attachments_count = 0;
        }
        if (chat) {
            const mine = auth.user?.id === message?.sender_id;
            chat.last_message = message.content;
            chat.last_message_created_at = message.created_at;
            chat.last_message_is_mine = mine;
            chat.last_message_sender_name = message.sender?.name ?? chat.last_message_sender_name;
            chat.last_message_type = message.type;
            chat.latest_message_attachments_count = message.attachments_count ?? message.attachments.length;
            // Count it; the open window marks it read once it's actually visible.
            // (Group/channel broadcasts are echoed back to the sender — that's not "unseen".)
            if (!mine) {
                chat.unseen_messages_count = (chat.unseen_messages_count ?? 0) + 1;
            }
            reorder();
        } else {
            // A message for a chat we haven't loaded yet — refresh the list.
            void loadChatList();
        }
        lastIncoming.value = message;
    }

    function clear(): void {
        chatList.value = null;
        currentChat.value = null;
    }

    return {
        chatList,
        currentChat,
        lastIncoming,
        isLoading,
        loadChatList,
        selectChatBySlug,
        upsertChat,
        clearCurrentChat,
        setLastMessage,
        addChat,
        decrementUnseen,
        seenAll,
        setReadState,
        receiveMessage,
        clear,
        removeChat,
    };
});