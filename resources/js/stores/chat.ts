import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getChatList } from '@/api';
import type { Chat, IncomingMessage } from '@/types';

function timestamp(value: string | null | undefined): number {
    return value ? new Date(value).getTime() : 0;
}

export default defineStore('chat', () => {
    /** `null` while the initial list is still loading. */
    const chatList = ref<Chat[] | null>(null);
    const chatListLoadPromise = ref<Promise<void> | null>(null);
    const currentChat = ref<Chat | null>(null);
    /** Signals the open chat window to append a just-received message. */
    const lastIncoming = ref<IncomingMessage | null>(null);

    const isLoading = computed(() => chatList.value === null);

    function findChat(chatId: number | undefined): Chat | undefined {
        if (chatId == null) {
            return undefined;
        }
        return chatList.value?.find((c) => c.id === chatId);
    }

    function reorder(): void {
        chatList.value?.sort(
            (a, b) => timestamp(b.last_message_created_at) - timestamp(a.last_message_created_at),
        );
    }

    async function loadChatList(): Promise<void> {
        if (chatListLoadPromise.value) {
            return chatListLoadPromise.value;
        }

        chatListLoadPromise.value = (async () => {
            chatList.value = await getChatList();
            chatListLoadPromise.value = null;
        })();

        return chatListLoadPromise.value;
    }

    /** Point `currentChat` at the chat for `slug`, or a placeholder if unknown. */
    async function selectChatBySlug(slug: string): Promise<void> {
        if (chatList.value === null) {
            await loadChatList();
        }

        currentChat.value = chatList.value?.find((c) => c.slug === slug) ?? { slug };
    }

    function clearCurrentChat(): void {
        currentChat.value = null;
    }

    function removeChat(chatId: number): void {
        if (chatList.value != null && chatList.value.length > 0) {
            let id = chatList.value.findIndex((c) => c.id == chatId);
            chatList.value.splice(id, 1);
        }
    }

    function setLastMessage(chatId: number, content: string, createdAt: string): void {
        const chat = findChat(chatId);
        if (chat) {
            chat.last_message = content;
            chat.last_message_created_at = createdAt;
            reorder();
        }
    }

    function addChat(id: number, slug: string, name: string, lastMessage: string, directUserId: number | null = null) {
        if (!chatList.value) {
            return;
        }
        // Avoid duplicates
        if (findChat(id)) {
            return;
        }
        const now = new Date().toISOString();
        chatList.value.push({
            id: id,
            slug,
            name: name,
            last_message: lastMessage,
            direct_user_id: directUserId,
            last_message_created_at: now,
            created_at: now,
            unseen_messages_count: 0,
            type: directUserId==null ? 'direct' : 'group',
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
    function receiveMessage(message: IncomingMessage): void {
        const chat = findChat(message.chat_id);
        if (message.attachments == null) {
            message.attachments = [];
        }
        if (chat) {
            chat.last_message = message.content;
            chat.last_message_created_at = message.created_at;
            // Always count it; the open window marks it read once it's actually visible.
            chat.unseen_messages_count = (chat.unseen_messages_count ?? 0) + 1;
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
