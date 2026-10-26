import type { Ref } from 'vue';
import { markChatAsRead } from '@/api';
import useChatStore from '@/stores/chat';
import { useReadReceipts } from '@/composables/useReadReceipts';
import type { Chat, Message } from '@/types';

interface Options {
    /** Getter for the open chat (a getter so it always reflects the current prop). */
    chat: () => Chat;
    messages: Ref<Message[]>;
    isIncoming: (message: Message) => boolean;
    /** From `useMessageScroll`: id of the last message visible in the viewport. */
    lastVisibleMessageId: () => string | null;
}

/**
 * Tracks what the user has (and hasn't) read in the open chat and syncs it
 * with the server and the chat store. Built on top of `useReadReceipts`.
 */
export function useChatReadState({ chat, messages, isIncoming, lastVisibleMessageId }: Options) {
    const chatStore = useChatStore();

    const ts = (m: Message) => new Date(m.created_at).getTime();
    const lastReadMs = () => {
        const readAt = chat().last_read_at;
        return readAt ? new Date(readAt).getTime() : 0;
    };
    const isUnseen = (m: Message) => isIncoming(m) && ts(m) > lastReadMs();

    /** True once `oldest` is at/before the last-read marker (no need to load further back). */
    const reachedLastRead = (oldest: Message) => ts(oldest) <= lastReadMs();

    /** Index of the first unseen message, or -1 (also -1 if the chat was never read). */
    const firstUnseenIndex = () => (chat().last_read_at ? messages.value.findIndex(isUnseen) : -1);

    const { markRead, cancel: cancelRead } = useReadReceipts((chatId, message) => {
        const readMs = Math.max(lastReadMs(), ts(message));
        chatStore.setReadState(
            chatId,
            message.created_at,
            messages.value.filter((m) => isIncoming(m) && ts(m) > readMs).length,
        );
    });

    /** Mark the newest unseen message at/above the viewport bottom as read. */
    function readVisible(): void {
        const chatId = chat().id;
        if (chatId == null) return;
        const id = lastVisibleMessageId();
        if (id == null) return;
        let i = messages.value.findIndex((m) => String(m.id) === id);
        while (i >= 0 && !isUnseen(messages.value[i])) i--;
        if (i < 0) return;
        markRead(messages.value[i], chatId);
    }

    /** Mark everything read optimistically; roll back if the request fails. */
    async function markAllRead(): Promise<void> {
        const current = chat();
        const chatId = current.id;
        if (chatId == null || !messages.value.some(isUnseen)) return;

        const prevReadAt = current.last_read_at;
        const prevCount = current.unseen_messages_count;
        cancelRead();
        chatStore.seenAll(chatId);
        try {
            await markChatAsRead(chatId);
        } catch (err) {
            current.last_read_at = prevReadAt;
            current.unseen_messages_count = prevCount;
            console.error('Failed to mark chat as read', err);
        }
    }

    return {
        isUnseen,
        reachedLastRead,
        firstUnseenIndex,
        readVisible,
        markAllRead,
        cancelRead,
    };
}