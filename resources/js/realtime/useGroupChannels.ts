import { onUnmounted, watch } from 'vue';
import { getEcho } from '@/realtime/echo';
import useChatStore from '@/stores/chat';
import type { Message } from '@/types';

/**
 * Subscribe to the private channel `chat.{id}` of every group / channel in the chat list,
 * and leave it again when the chat disappears (left, kicked, deleted).
 *
 * Backend counterpart: `NewChatMessage` (broadcastAs `message.new`, payload `{ message }`).
 * The leading dot in `.message.new` is required by Echo for custom `broadcastAs` names.
 */
export function useGroupChannels(): void {
    const chat = useChatStore();
    const echo = getEcho();
    const joined = new Set<number>();

    const channelName = (id: number) => `chat.${id}`;

    watch(
        () =>
            (chat.chatList ?? [])
                .filter((c) => c.id != null && c.type != null && c.type !== 'direct')
                .map((c) => c.id as number),
        (ids) => {
            const wanted = new Set(ids);

            for (const id of wanted) {
                if (joined.has(id)) continue;
                echo.private(channelName(id)).listen('.message.new', (event: { message: Message }) => {
                    console.log(`CHANNEL chat.${id} , new message`, event);
                    chat.receiveMessage(event.message);
                });
                joined.add(id);
            }

            for (const id of [...joined]) {
                if (wanted.has(id)) continue;
                echo.leave(channelName(id));
                joined.delete(id);
            }
        },
        { immediate: true },
    );

    onUnmounted(() => {
        joined.forEach((id) => echo.leave(channelName(id)));
        joined.clear();
    });
}