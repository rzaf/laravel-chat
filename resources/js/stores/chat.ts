import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getChatList } from '@/api';
import type { Chat, Message } from '@/types';
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
            (a, b) => timestamp(b.last_message_created_at) - timestamp(a.last_message_created_at),
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
            chat.last_message_created_at = newMessage.created_at;
            chat.last_message_is_mine = true;
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
            type: directUserId == null ? 'direct' : 'group',
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
            chat.last_message = message.content;
            chat.last_message_created_at = message.created_at;
            chat.last_message_is_mine = auth.user?.id === message?.sender_id;
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