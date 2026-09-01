import { markPrivateMessageRead } from '@/api';
import { messageSenderId } from '@/types';
import type { Message } from '@/types';

/**
 * Marks the other participant's unread messages as read. De-duplicates
 * in-flight requests and updates each message's `read_at` locally so a message
 * is never marked twice.
 *
 * @param onRead called with the chat id after a message is confirmed read,
 *               so the caller can decrement the unseen counter.
 */
export function useReadReceipts(onRead: (chatId: number) => void) {
    const inFlight = new Set<number>();

    function markRead(messages: Message[], selfId: number | undefined): void {
        for (const message of messages) {
            const id = message.id;
            if (
                id == null ||
                message.read_at != null ||
                messageSenderId(message) === selfId ||
                inFlight.has(id)
            ) {
                continue;
            }

            inFlight.add(id);
            markPrivateMessageRead(id)
                .then(() => {
                    message.read_at = new Date().toISOString();
                    if (message.chat_id != null) {
                        onRead(message.chat_id);
                    }
                })
                .catch(() => inFlight.delete(id));
        }
    }

    return { markRead };
}
