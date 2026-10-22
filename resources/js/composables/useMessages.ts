import { computed, ref } from 'vue';
import { isAxiosError } from 'axios';
import { getDirectMessages, sendDirectMessage } from '@/api';
import { groupMessagesByDate } from '@/helpers/messageGrouping';
import type { Message } from '@/types';

const PAGE_SIZE = 20;

/**
 * Owns the message timeline for a single open chat: loading, pagination,
 * sending, and receiving. Purely data — no DOM or scrolling concerns.
 */
export function useMessages() {
    /** Chronological (oldest → newest); the API returns newest-first. */
    const messages = ref<Message[]>([]);
    const timeline = computed(() => groupMessagesByDate(messages.value));

    const isChatEmpty = ref<boolean | null>(null);
    const userExists = ref<boolean | null>(null);
    const isLastPage = ref(false);
    let page = 1;
    /** Slug of the chat currently shown; used to ignore responses for a chat we've left. */
    let currentSlug: string | null = null;

    function reset(): void {
        messages.value = [];
        isChatEmpty.value = null;
        userExists.value = null;
        isLastPage.value = false;
        page = 1;
    }

    async function loadInitial(slug: string): Promise<void> {
        reset();
        currentSlug = slug;
        try {
            const { data } = await getDirectMessages(slug);
            userExists.value = true;
            isLastPage.value = data.length < PAGE_SIZE;
            isChatEmpty.value = data.length === 0;
            messages.value = data.reverse();
        } catch (err) {
            if (isAxiosError(err) && err.response?.status === 404) {
                userExists.value = false;
            } else {
                throw err;
            }
        }
    }

    /** Fetch the next older page and prepend it. Returns whether rows were added. */
    async function loadOlder(slug: string): Promise<boolean> {
        if (isLastPage.value) {
            return false;
        }
        page += 1;
        const { data } = await getDirectMessages(slug, page);
        if (data.length === 0) {
            isLastPage.value = true;
            return false;
        }
        if (data.length < PAGE_SIZE) {
            isLastPage.value = true;
        }
        messages.value.unshift(...data.reverse());
        return true;
    }

    async function send(
        slug: string,
        content: string,
        attachments: File[] = [],
        replyToId: number | null = null,
        forwardedFromMessageId: number | null = null,
    ): Promise<Message> {
        const message = await sendDirectMessage(slug, content, attachments, replyToId, forwardedFromMessageId);
        // The user may have switched chats while the request was in flight;
        // don't leak the new message into another chat's timeline.
        if (slug === currentSlug) {
            messages.value.push(message);
            isChatEmpty.value = false;
        }
        return message;
    }

    function append(message: Message): void {
        messages.value.push(message);
    }

    function removeMessage(messageId: any): void {
        if (messages.value != null && messages.value.length > 0) {
            const index = messages.value.findIndex((c) => c.id == messageId);
            // findIndex returns -1 when missing; splice(-1, 1) would delete the LAST message.
            if (index !== -1) {
                messages.value.splice(index, 1);
            }
        }
    }

    return {
        messages,
        timeline,
        isChatEmpty,
        userExists,
        isLastPage,
        reset,
        loadInitial,
        loadOlder,
        send,
        append,
        removeMessage,
    };
}