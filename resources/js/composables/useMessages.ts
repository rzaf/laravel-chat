import { computed, ref } from 'vue';
import { isAxiosError } from 'axios';
import { deleteMessage, getMessages, sendMessage, updateMessage } from '@/api';
import { groupMessagesByDate } from '@/helpers/messageGrouping';
import type { ChatType, Message } from '@/types';

const PAGE_SIZE = 20;
const MAX_HISTORY_PAGES = 50;

type MessageId = number | string;

/** Ids may arrive as number or string depending on the source (API vs DOM). */
export const sameId = (a: unknown, b: unknown): boolean => String(a) === String(b);

/**
 * Owns the message timeline for a single open chat: loading, pagination,
 * sending, editing, deleting and receiving. Purely data — no DOM or scrolling concerns.
 */
export function useMessages() {
    /** Chronological (oldest → newest); the API returns newest-first. */
    const messages = ref<Message[]>([]);
    const timeline = computed(() => groupMessagesByDate(messages.value));
    const hasMessages = computed(() => messages.value.length > 0);
    /** id → message lookup so callers can resolve a message in O(1). */
    const messagesById = computed(() => new Map(messages.value.map((m) => [String(m.id), m])));

    const isChatEmpty = ref<boolean | null>(null);
    const userExists = ref<boolean | null>(null);
    const isLastPage = ref(false);

    let page = 1;
    let loadingOlder = false;
    /** Slug of the chat currently shown; used to ignore sends for a chat we've left. */
    let currentSlug: string | null = null;
    /** Kind of chat currently shown: decides which endpoints are used (direct vs group/channel). */
    let currentType: ChatType = 'direct';
    /** Bumped on every reset so late responses for a previous chat can be discarded. */
    let generation = 0;

    function reset(): void {
        generation += 1;
        messages.value = [];
        isChatEmpty.value = null;
        userExists.value = null;
        isLastPage.value = false;
        loadingOlder = false;
        page = 1;
    }

    function findMessage(id: unknown): Message | undefined {
        return messages.value.find((m) => sameId(m.id, id));
    }

    async function loadInitial(slug: string, type: ChatType = 'direct'): Promise<void> {
        reset();
        currentSlug = slug;
        currentType = type;
        const gen = generation;
        try {
            const { data } = await getMessages({ slug, type });
            if (gen !== generation) return; // user already moved to another chat
            userExists.value = true;
            isLastPage.value = data.length < PAGE_SIZE;
            isChatEmpty.value = data.length === 0;
            messages.value = data.reverse();
        } catch (err) {
            if (isAxiosError(err) && err.response?.status === 404) {
                if (gen === generation) {
                    userExists.value = false;
                }
            } else {
                throw err;
            }
        }
    }

    /** Fetch the next older page and prepend it. Returns whether rows were added. */
    async function loadOlder(slug: string): Promise<boolean> {
        if (isLastPage.value || loadingOlder) {
            return false;
        }
        const gen = generation;
        loadingOlder = true;
        try {
            const nextPage = page + 1;
            const { data } = await getMessages({ slug, type: currentType }, nextPage);
            if (gen !== generation) return false;
            // Only advance after success so a failed request doesn't skip a page.
            page = nextPage;
            if (data.length === 0) {
                isLastPage.value = true;
                return false;
            }
            if (data.length < PAGE_SIZE) {
                isLastPage.value = true;
            }
            messages.value.unshift(...data.reverse());
            return true;
        } finally {
            if (gen === generation) {
                loadingOlder = false;
            }
        }
    }

    /**
     * Keep loading older pages until `reached(oldestLoaded)` is true,
     * the history ends, or the page cap is hit.
     */
    async function loadOlderUntil(
        slug: string,
        reached: (oldest: Message) => boolean,
        maxPages = MAX_HISTORY_PAGES,
    ): Promise<void> {
        for (let n = 0; n < maxPages && !isLastPage.value; n++) {
            const oldest = messages.value[0];
            if (!oldest || reached(oldest)) break;
            if (!(await loadOlder(slug))) break;
        }
    }

    async function send(
        slug: string,
        content: string,
        attachments: File[] = [],
        replyToId: number | null = null,
        forwardedFromMessageId: number | null = null,
    ): Promise<Message> {
        const message = await sendMessage({ slug, type: currentType }, content, attachments, replyToId, forwardedFromMessageId);
        if (message.attachments != null) {
            message.attachments_count = message.attachments.length;
        }
        // The user may have switched chats while the request was in flight;
        // don't leak the new message into another chat's timeline.
        if (slug === currentSlug) {
            // append(message);
            messages.value.push(message);
            isChatEmpty.value = false;
        }
        return message;
    }

    /** Add a realtime message (ignored if it is already in the timeline). */
    function append(message: Message): void {
        if (message.id != null && findMessage(message.id)) {
            return;
        }
        messages.value.push(message);
        isChatEmpty.value = false;
    }

    /** Update a message on the server, then mirror the change locally. */
    async function edit(messageId: MessageId, content: string): Promise<void> {
        await updateMessage(String(messageId), content);
        const target = findMessage(messageId);
        if (target) {
            target.content = content;
            target.updated_at = new Date().toISOString();
        }
    }

    /** Delete a message on the server, then drop it locally. */
    async function remove(messageId: MessageId, deleteForEveryone = false): Promise<void> {
        await deleteMessage(String(messageId), deleteForEveryone);
        removeMessage(messageId);
    }

    /** Local-only removal. */
    function removeMessage(messageId: MessageId): void {
        const index = messages.value.findIndex((m) => sameId(m.id, messageId));
        // findIndex returns -1 when missing; splice(-1, 1) would delete the LAST message.
        if (index !== -1) {
            messages.value.splice(index, 1);
        }
    }

    return {
        messages,
        timeline,
        hasMessages,
        messagesById,
        isChatEmpty,
        userExists,
        isLastPage,
        reset,
        findMessage,
        loadInitial,
        loadOlder,
        loadOlderUntil,
        send,
        append,
        edit,
        remove,
        removeMessage,
    };
}