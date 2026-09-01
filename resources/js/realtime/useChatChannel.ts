import { onUnmounted } from 'vue';
import { getEcho } from '@/realtime/echo';
import useChatStore from '@/stores/chat';
import type { IncomingMessage } from '@/types';

/**
 * Subscribe to the current user's private channel and funnel incoming
 * messages into the chat store. Automatically leaves the channel on unmount.
 */
export function useChatChannel(userId: number): void {
    const chat = useChatStore();
    const echo = getEcho();
    const channelName = `Users.${userId}`;

    echo.private(channelName).listen('NewPrivateMessage', (event: IncomingMessage) => {
        chat.receiveMessage(event);
    });

    onUnmounted(() => {
        echo.leave(channelName);
    });
}
