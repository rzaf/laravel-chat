import { ref } from 'vue';
import { markMessageAsRead } from '@/api';
import type { Message } from '@/types';

/**
 * Manages debounced and cancelable read receipts as the user scrolls or opens chats.
 */
export function useReadReceipts(onRead: (chatId: number, message: Message) => void) {
    let timeoutId: ReturnType<typeof setTimeout> | null = null;
    let abortController: AbortController | null = null;
    let pendingId: Message['id'] | null = null;

    /**
     * Debounced function to mark a message as read.
     * Cancels any pending timer or in-flight request when a new message is passed.
     */
    function markRead(message: Message | null, chatIdOverride?: number): void {
        const chatId = chatIdOverride ?? message?.chat_id;
        if (!message || message.id == null || chatId == null) {
            return;
        }
        // Same message already scheduled/in flight — keep it, don't restart.
        if (pendingId === message.id) {
            return;
        }
        pendingId = message.id;

        // Clear the previous pending debounce timer
        if (timeoutId) {
            clearTimeout(timeoutId);
        }

        // Abort any active in-flight request for the previous message
        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();
        const signal = abortController.signal;
        const messageId = message.id;
        // const chatId = message.chat_id;

        // 400ms debounce delay during scrolling
        timeoutId = setTimeout(async () => {
            try {
                await markMessageAsRead(messageId, signal);
                pendingId = null;
                onRead(chatId, message);
            } catch (err: any) {
                if (err.name !== 'CanceledError' && err.code !== 'ERR_CANCELED') {
                    pendingId = null; // allow retry
                    console.error('Failed to mark message as read', err);
                }
            }
        }, 400);
    }

    function cancel(): void {
        if (timeoutId) clearTimeout(timeoutId);
        if (abortController) abortController.abort();
        timeoutId = null;
        pendingId = null;
    }

    return { markRead, cancel };
}