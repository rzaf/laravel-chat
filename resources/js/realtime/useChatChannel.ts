import { onUnmounted } from 'vue';
import { getEcho } from '@/realtime/echo';
import { useGroupChannels } from '@/realtime/useGroupChannels';
import useChatStore from '@/stores/chat';
import type { Chat, Message } from '@/types';

/**
- Subscribe to the current user's private channel (direct messages) and to the
- channel of every group / channel, funnelling incoming messages into the chat store.
- Automatically leaves the channels on unmount.
 */
export function useChatChannel(userId: number): void {
    const chat = useChatStore();
    const echo = getEcho();
    const channelName = `Users.${userId}`;

    echo.private(channelName).listen('NewPrivateMessage', (event: Message) => {
        console.log('CHANNEL NewPrivateMessage , new private message', event);
        chat.receiveMessage(event);
    });

    echo.private(channelName).listen('.chat.new', (event: Chat) => {
        console.log('CHANNEL .chat.new , new chat added', event);
        // todo: later just add the new chat
        chat.loadChatList();
    });

    // groups & channels: `chat.{id}` — follows the chat list automatically
    useGroupChannels();

    onUnmounted(() => {
        echo.leave(channelName);
    });
}