<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { onKeyStroke } from '@vueuse/core';
import useAuthStore from '@/stores/auth';
import useChatStore from '@/stores/chat';
import { useMessages } from '@/composables/useMessages';
import { useMessageScroll } from '@/composables/useMessageScroll';
import { useChatReadState } from '@/composables/useChatReadState';
import { useMessageActions } from '@/composables/useMessageActions';
import { hasOpenOverlay } from '@/composables/useOverlayManager';
import { forwardMessage } from '@/api/index.ts';
import { isDateSeparator } from '@/types';
import type { Chat, Message, MessageGroup } from '@/types';
import MessageBubble from '@/components/chat/MessageBubble.vue';
import MessageComposer from '@/components/chat/MessageComposer.vue';
import ContextMenu from '@/components/common/ContextMenu.vue';
import ForwardModal from '@/components/modals/ForwardModal.vue';
import Avatar from './Avatar.vue';
import BackArrowIcon from '@/assets/svgs/BackArrow.svg';
import ChevronDownIcon from '@/assets/svgs/ChevronDown.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import ReplyIcon from '@/assets/svgs/Reply.svg';
import CloseIcon from '@/assets/svgs/Close.svg';
import CopyIcon from '@/assets/svgs/Copy.svg';
import EditIcon from '@/assets/svgs/Edit.svg';
import CircleCheckIcon from '@/assets/svgs/CircleCheck.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';

/* ------------------------------------------------------------------ setup */

const props = defineProps<{ chat: Chat }>();
const emit = defineEmits<{
    (e: 'openChatProfile', chat: Chat): void;
}>();

const router = useRouter();
const auth = useAuthStore();
const chatStore = useChatStore();
const selfId = computed(() => auth.user?.id);

/* ------------------------------------------------------------------ state */

const listRef = useTemplateRef<HTMLElement>('listRef');
const forwardModalRef = useTemplateRef<InstanceType<typeof ForwardModal>>('forwardModalRef');

const draft = ref('');
const attachments = ref<File[]>([]);
const sending = ref(false);
const isDraggingOver = ref(false);

/* ------------------------------------------------------------ composables */

const {
    messages, timeline, hasMessages, messagesById, isChatEmpty, userExists,
    findMessage, loadInitial, loadOlder, loadOlderUntil, send, append, edit, remove,
} = useMessages();

const {
    showGoToBottom, scrollToBottom, scrollToBottomAfterRender, scrollToTop, scrollToMessageBottom,
    lastVisibleMessageId, isAtTop, isNearBottom, updateGoToBottom, preserveOnPrepend,
    waitForList, jumpToMessage,
} = useMessageScroll(listRef);

const isMine = (m: Message) => m.sender_id === selfId.value;

const {
    reachedLastRead, firstUnseenIndex, readVisible, markAllRead, cancelRead,
} = useChatReadState({
    chat: () => props.chat,
    messages,
    isIncoming: (m) => !isMine(m),
    lastVisibleMessageId,
});

const {
    isOpen, x, y, activeMenuMessage, handleOpenMessageMenu, handleCloseMenu, handleMenuAction,
    replyingToMessage, editingMessage, cancelEdit, resetComposerContext,
} = useMessageActions({
    draft,
    findMessage,
    removeMessage: remove,
    openForward: (message) => forwardModalRef.value?.open(message),
});

/* ----------------------------------------------------------- derived data */

/** Display name of the other side of the conversation. */
const chatLabel = computed(() => props.chat.name ?? props.chat.slug);
const authorLabel = (m: Message) => (isMine(m) ? 'You' : chatLabel.value);

const statusText = computed(() => {
    if (props.chat.is_typing) return 'typing…';
    if (props.chat.is_online) return 'online';
    return '';
});

/** Timeline grouped into date sections (a leading unlabeled group if no separator comes first). */
const groups = computed<MessageGroup[]>(() => {
    const out: MessageGroup[] = [];
    timeline.value.forEach((item, index) => {
        if (isDateSeparator(item)) {
            out.push({ key: `date-${index}`, label: item.label, messages: [] });
            return;
        }
        if (!out.length) out.push({ key: 'start', label: '', messages: [] });
        out[out.length - 1].messages.push({
            id: String(item.id),
            key: item.id ?? `m-${index}`,
            text: item.content,
            time: item.created_at,
            updatedAt: item.updated_at,
            forwardedFrom: item.forwarded_from,
            isSent: isMine(item),
            attachments: item.attachments,
            attachments_count: item.attachments_count,
        });
    });
    return out;
});

const replyPreviewAuthor = computed(() => {
    const m = replyingToMessage.value;
    return m ? authorLabel(m) : '';
});

const replyPreviewText = computed(() => {
    const m = replyingToMessage.value;
    if (!m) return '';
    if (m.content) return m.content;
    return m.attachments?.length ? 'Attachment' : '';
});

const messageOf = (row: { id?: string }) => (row.id != null ? messagesById.value.get(String(row.id)) : undefined);

function replyAuthorOf(row: { id?: string }): string | null | undefined {
    const reply = messageOf(row)?.reply_to;
    if (!reply) return null;
    return authorLabel(messagesById.value.get(String(reply.id)) ?? reply);
}

/* ------------------------------------------------- opening / scrolling */

/** Land on the first unseen message, or the bottom when everything is read. */
function positionInitialScroll(): void {
    const first = firstUnseenIndex();
    if (first > 0) {
        scrollToMessageBottom(messages.value[first - 1].id!);
    } else if (first === 0) {
        scrollToTop();
    } else {
        scrollToBottom();
    }
}

async function openChat(slug: string): Promise<void> {
    const isStale = () => props.chat.slug !== slug;

    cancelRead();
    const previousEl = listRef.value;
    showGoToBottom.value = false;

    await loadInitial(slug);
    if (isStale()) return;

    if (props.chat.last_read_at) {
        await loadOlderUntil(slug, reachedLastRead);
        if (isStale()) return;
    }

    await waitForList(previousEl, () => isChatEmpty.value === true || userExists.value === false);
    if (isStale()) return;

    positionInitialScroll();
    updateGoToBottom();
    readVisible();
}

async function handleScroll(): Promise<void> {
    updateGoToBottom();
    readVisible();

    const slug = props.chat.slug;
    if (slug && isAtTop()) {
        await preserveOnPrepend(() => loadOlder(slug).then(() => undefined));
    }
}

async function goToBottom(): Promise<void> {
    scrollToBottom(true);
    await markAllRead();
}

/* ------------------------------------------------------------ send / edit */

/** Reflect a just-sent message in the chat list preview. */
function updateLastMessage(chatId: number | null | undefined, sent: Message): void {
    if (chatId == null) return;
    chatStore.setLastMessage({
        chat_id: chatId,
        content: sent.content,
        created_at: sent.created_at,
    });
}

async function handleSend(): Promise<void> {
    const content = draft.value.trim();
    const slug = props.chat.slug;
    if (!slug || userExists.value === false || (!content && attachments.value.length === 0)) {
        return;
    }
    sending.value = true;
    try {
        if (editingMessage.value) {
            const editedId = editingMessage.value.id;
            if (editedId != null) {
                await edit(editedId, content);
            }
            cancelEdit();
            return;
        }

        const replyToId = replyingToMessage.value?.id != null ? Number(replyingToMessage.value.id) : null;
        const message = await send(slug, content, attachments.value, replyToId);

        // First message of a brand-new chat: register it in the list.
        if (props.chat.id == null) {
            chatStore.addChat(
                message?.chat?.id || 0,
                slug,
                message?.chat?.name || slug,
                content,
                props.chat.direct_user_id,
            );
            chatStore.selectChatBySlug(slug);
        }

        replyingToMessage.value = null;
        updateLastMessage(props.chat.id, message);
        draft.value = '';
        attachments.value = [];

        await scrollToBottomAfterRender();
        if (props.chat.id != null) {
            chatStore.seenAll(props.chat.id);
        }
    } catch (error) {
        console.error('Failed to send or update message', error);
    } finally {
        sending.value = false;
    }
}

/* ---------------------------------------------------------------- forward */

const forwardsInFlight = new Set<string>();

async function forwardTo(targetChat: Chat, msg: Message): Promise<void> {
    const slug = targetChat.slug;
    if (!slug) return;
    const key = `${msg.id}:${slug}`;
    if (forwardsInFlight.has(key)) return;
    forwardsInFlight.add(key);
    try {
        const isCurrentChat = slug === props.chat.slug;
        const sent = isCurrentChat
            ? await send(slug, msg.content ?? '', [], null, Number(msg.id))
            : await forwardMessage(slug, msg);

        updateLastMessage(targetChat.id, sent);
        if (isCurrentChat) {
            await scrollToBottomAfterRender();
        }
    } finally {
        forwardsInFlight.delete(key);
    }
}

async function handleForwardMessage(targetChats: Chat[], msgToForward: Message): Promise<void> {
    if (msgToForward.id == null) return;
    const targets = targetChats.filter((c) => c.slug);

    const results = await Promise.allSettled(targets.map((chat) => forwardTo(chat, msgToForward)));
    results.forEach((r, i) => {
        if (r.status === 'rejected') {
            console.error(`Failed to forward message to ${targets[i].slug}`, r.reason);
        }
    });

    const delivered = targets.filter((_, i) => results[i].status === 'fulfilled');
    if (targets.length === 1 && delivered.length === 1 && delivered[0].slug !== props.chat.slug) {
        router.push(`/users/${delivered[0].slug}`);
    }
}

/* ------------------------------------------------------------ drag & drop */

function handleDragOver(e: DragEvent): void {
    e.preventDefault();
    isDraggingOver.value = true;
}

function handleDragLeave(e: DragEvent): void {
    e.preventDefault();
    const currentTarget = e.currentTarget as HTMLElement;
    if (e.relatedTarget && currentTarget.contains(e.relatedTarget as Node)) return;
    isDraggingOver.value = false;
}

function handleDrop(e: DragEvent): void {
    e.preventDefault();
    isDraggingOver.value = false;
    if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
        attachments.value.push(...Array.from(e.dataTransfer.files));
    }
}

/* ------------------------------------------------- lifecycle & listeners */

watch(
    () => props.chat.slug,
    (slug) => {
        resetComposerContext();
        if (slug) {
            void openChat(slug);
        }
    },
    { immediate: true },
);

watch(
    () => chatStore.lastIncoming,
    (incoming) => {
        if (!incoming || incoming.chat_id !== props.chat.id) {
            return;
        }
        const wasNearBottom = isNearBottom(120);
        append(incoming);
        if (wasNearBottom) {
            void nextTick(() => {
                scrollToBottom(true);
                readVisible();
            });
        }
    },
);

onKeyStroke('Escape', () => {
    if (hasOpenOverlay.value) return;
    if (editingMessage.value) {
        cancelEdit();
        return;
    }
    if (replyingToMessage.value) {
        replyingToMessage.value = null;
        return;
    }
    router.push('/');
});

onBeforeUnmount(cancelRead);
</script>

<template>
    <div class="relative flex flex-col h-full min-h-0" @dragover="handleDragOver" @dragleave="handleDragLeave"
        @drop="handleDrop">
        <!-- Drag & Drop Overlay Cue -->
        <div v-if="isDraggingOver"
            class="absolute inset-0 z-51 flex items-center justify-center bg-blue-500/10 backdrop-blur-[2px] border-2 border-dashed border-blue-500 pointer-events-none rounded-lg m-2">
            <div
                class="px-6 py-3 bg-white/90 dark:bg-gray-800/90 text-blue-600 dark:text-blue-400 font-medium rounded-xl shadow-lg flex items-center gap-2">
                <span>Drop files here to attach</span>
            </div>
        </div>
        <!-- Header -->
        <header id="top-profile"
            class="z-10 flex items-center h-16 gap-1 px-3 bg-white border-b border-gray-100 dark:bg-gray-800 dark:border-gray-700">
            <RouterLink to="/" aria-label="Back to chats"
                class="grid cursor-pointer transition-colors rounded-full lg:hidden size-10 place-items-center hover:bg-gray-200 dark:hover:bg-gray-700">
                <BackArrowIcon class="size-6 fill-gray-500 dark:fill-gray-300" />
            </RouterLink>

            <button type="button"
                class="cursor-pointer flex items-center min-w-0 gap-2 px-2 py-1 text-left transition-colors rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click="emit('openChatProfile', chat)">
                <Avatar class="size-10 shrink-0" :src="chat.profile_photo || '/storage/default-profile.png'" />
                <span class="flex flex-col min-w-0">
                    <span class="text-base font-medium leading-tight text-gray-900 truncate dark:text-gray-100">
                        {{ chat.name ?? chat.slug }}
                    </span>
                    <span
                        class="flex items-center gap-1.5 text-sm leading-tight text-gray-500 truncate dark:text-gray-400">
                        <span class="truncate">@{{ chat.slug }}</span>
                        <template v-if="statusText">
                            <span aria-hidden="true">·</span>
                            <span class="text-blue-600 dark:text-blue-400">{{ statusText }}</span>
                        </template>
                    </span>
                </span>
            </button>
        </header>

        <!-- Jump to latest -->
        <Transition enter-from-class="opacity-0 translate-y-2" enter-active-class="transition duration-150"
            leave-to-class="opacity-0 translate-y-2" leave-active-class="transition duration-150">
            <button v-show="showGoToBottom" type="button" aria-label="Scroll to latest message"
                class="absolute cursor-pointer z-30 grid bg-white rounded-full shadow-lg left-4 bottom-24 size-11 place-items-center dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600"
                @click="goToBottom">
                <ChevronDownIcon class="size-7 fill-gray-700 dark:fill-gray-200" />
                <span v-show="(chat.unseen_messages_count ?? 0) > 0"
                    class="absolute grid px-1.5 text-xs font-medium text-white bg-blue-600 rounded-full -top-2 -right-1 min-w-5 h-5 place-items-center">
                    {{ chat.unseen_messages_count }}
                </span>
            </button>
        </Transition>

        <Transition name="chat-section" mode="out-in">
            <div :key="chat.slug" class="flex flex-col flex-1 min-h-0">
                <!-- Loading -->
                <div v-if="userExists === null && !hasMessages" class="flex items-center justify-center flex-1">
                    <div class="flex items-center justify-center space-x-2" role="status" aria-label="Loading messages">
                        <span style="animation-delay: -300ms"
                            class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                        <span style="animation-delay: -200ms"
                            class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                        <span style="animation-delay: -100ms"
                            class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                    </div>
                </div>

                <!-- Empty conversation -->
                <div v-else-if="isChatEmpty === true || userExists === false"
                    class="flex flex-col items-center justify-center flex-1 gap-2 px-6 text-center">
                    <Avatar class="mb-2 size-20" :src="chat.profile_photo || '/storage/default-profile.png'" />
                    <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100">
                        {{ userExists === false ? 'User does not exist' : `No messages with ${chat.name ?? chat.slug} yet` }}
                    </h2>
                    <p v-if="userExists !== false" class="text-sm text-gray-500 dark:text-gray-400">
                        Say hello to start the conversation.
                    </p>
                </div>

                <!-- Message timeline -->
                <div v-else ref="listRef" role="log" aria-live="polite"
                    class="flex-1 w-full min-h-0 px-1 pt-3 pb-2 overflow-y-auto scrollable" @scroll="handleScroll">
                    <section v-for="group in groups" :key="group.key">
                        <div v-if="group.label" class="sticky z-40 w-fit mx-auto flex justify-center py-1.5 top-1">
                            <span dir="rtl"
                                class="px-3 py-1 text-xs font-medium text-gray-600 rounded-full shadow-sm bg-white/90 backdrop-blur dark:bg-gray-700/90 dark:text-gray-200">
                                {{ group.label }}
                            </span>
                        </div>
                        <MessageBubble v-for="row in group.messages" :key="row.key" :chat-type="chat.type" :data-message-id="row.id"
                            :message="row.text" :time="row.time" :updated-at="row.updatedAt" :is-sent="row.isSent" :attachments="row.attachments"
                            :replyToMessageId="messageOf(row)?.reply_to_id" :reply-to="messageOf(row)?.reply_to" :reply-author="replyAuthorOf(row)"
                            :forwarded-from="messageOf(row)?.forwarded_from" @jump-to-reply="jumpToMessage"
                            :is-menu-open="activeMenuMessage?.key === row.key"
                            @open-menu="handleOpenMessageMenu(row, $event)" />
                    </section>
                </div>
            </div>
        </Transition>

        <!-- Reply Preview Banner -->
        <div v-if="replyingToMessage"
            class="flex items-center justify-between px-4 py-2 bg-gray-100 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
            <div class="flex items-center gap-3 min-w-0">
                <ReplyIcon class="size-5 text-blue-600 dark:text-blue-400 shrink-0" />
                <div class="flex flex-col min-w-0 pl-2 border-l-2 border-blue-500">
                    <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 truncate">
                        Replying to {{ replyPreviewAuthor }}
                    </span>
                    <span class="text-sm text-gray-700 dark:text-gray-200 truncate">
                        {{ replyPreviewText }}
                    </span>
                </div>
            </div>
            <button type="button" aria-label="Cancel reply"
                class="grid rounded-full size-7 place-items-center hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer"
                @click="replyingToMessage = null">
                <CloseIcon class="size-4 text-gray-500" />
            </button>
        </div>

        <!-- Edit Preview Banner -->
        <div v-if="editingMessage"
            class="flex items-center justify-between px-4 py-2 bg-amber-50 dark:bg-amber-950/30 border-t border-amber-200 dark:border-amber-800/50">
            <div class="flex items-center gap-3 min-w-0">
                <EditIcon class="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
                <div class="flex flex-col min-w-0 pl-2 border-l-2 border-amber-500">
                    <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 truncate">
                        Edit message
                    </span>
                    <span class="text-sm text-gray-700 dark:text-gray-200 truncate">
                        {{ editingMessage.content }}
                    </span>
                </div>
            </div>
            <button type="button" aria-label="Cancel edit"
                class="grid rounded-full size-7 place-items-center hover:bg-amber-100 dark:hover:bg-amber-900/50 cursor-pointer"
                @click="cancelEdit">
                <CloseIcon class="size-4 text-gray-500" />
            </button>
        </div>

        <MessageComposer v-model="draft" :sending="sending" :attachments="attachments" @send="handleSend" />

        <!-- Forward Modal -->
        <ForwardModal ref="forwardModalRef" @forward="handleForwardMessage" />

        <!-- Shared Modern Context Menu -->
        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="handleCloseMenu">
            <ul class="py-1 space-y-0.5 text-sm">
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('reply')">
                        <ReplyIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Reply</span>
                    </button>
                </li>
                <li v-if="activeMenuMessage?.isSent && activeMenuMessage?.forwardedFrom==null">
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('edit')">
                        <EditIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Edit</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('forward')">
                        <ForwardIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Forward</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('copy')">
                        <CopyIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Copy Text</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('select')">
                        <CircleCheckIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Select</span>
                    </button>
                </li>
                <div class="my-1 border-t border-gray-100 dark:border-gray-600" />
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 rounded-xl transition-colors"
                        @click="handleMenuAction('delete')">
                        <TrashIcon class="size-4 text-red-500 shrink-0" />
                        <span>Delete</span>
                    </button>
                </li>
            </ul>
        </ContextMenu>
    </div>
</template>

<style scoped>
.chat-section-enter-active {
    transition: opacity 200ms ease-in;
}

.chat-section-leave-active {
    transition: opacity 350ms ease;
}

.chat-section-enter-from,
.chat-section-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .chat-section-enter-active,
    .chat-section-leave-active {
        transition: none;
    }
}
</style>