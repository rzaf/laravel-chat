<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import useChatStore from '@/stores/chat';
import { useMessages } from '@/composables/useMessages';
import { useMessageScroll } from '@/composables/useMessageScroll';
import { useReadReceipts } from '@/composables/useReadReceipts';
import { isDateSeparator, messageSenderId } from '@/types';
import type { Attachment, Chat, Message } from '@/types';
import MessageBubble from '@/components/chat/MessageBubble.vue';
import MessageComposer from '@/components/chat/MessageComposer.vue';
import ContextMenu from '@/components/common/ContextMenu.vue';
import BackArrowIcon from '@/assets/svgs/BackArrow.svg';
import ChevronDownIcon from '@/assets/svgs/ChevronDown.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import ReplyIcon from '@/assets/svgs/Reply.svg';
import CopyIcon from '@/assets/svgs/Copy.svg';
import CircleCheckIcon from '@/assets/svgs/CircleCheck.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';
import Avatar from './Avatar.vue';
import { onKeyStroke } from '@vueuse/core';
import { hasOpenOverlay } from '@/composables/useOverlayManager';
import { deleteMessage, markChatAsRead } from '@/api/index.ts';
import { useContextMenu } from '@/composables/useContextMenu';
import { useConfirm } from '@/composables/useConfirm.ts';

const props = defineProps<{ chat: Chat }>();
const router = useRouter();

const emit = defineEmits<{
    (e: 'openChatProfile', chat: Chat): void;
}>();

const auth = useAuthStore();
const chatStore = useChatStore();
const selfId = computed(() => auth.user?.id);

const listRef = useTemplateRef<HTMLElement>('listRef');
const draft = ref('');
const attachments = ref<File[]>([]);
const sending = ref(false);

/* ---------- shared context menu state ---------- */
const { isOpen, x, y, open, close } = useContextMenu();
const activeMenuMessage = ref<MessageRow | null>(null);

function handleOpenMessageMenu(message: MessageRow, event: MouseEvent) {
    activeMenuMessage.value = message;
    open(event);
}

function handleCloseMenu() {
    close();
    activeMenuMessage.value = null;
}


const { confirm } = useConfirm();
async function handleMenuAction(action: 'reply' | 'forward' | 'copy' | 'select' | 'delete') {
    const msg = activeMenuMessage.value;
    if (!msg) return;

    switch (action) {
        case 'reply':
            console.log('Reply to message:', msg);
            // TODO: Complete reply logic
            break;
        case 'forward':
            console.log('Forward message:', msg);
            // TODO: Complete forward logic
            break;
        case 'copy':
            if (msg.text) {
                navigator.clipboard.writeText(msg.text).catch((err) => {
                    console.error('Failed to copy text', err);
                });
            }
            break;
        case 'select':
            console.log('select message:', msg);
            // TODO: Complete select logic
            break;
        case 'delete':
            console.log('Delete message:', msg);
            const res = await confirm({
                title: 'Delete Message',
                message: `Are you sure to delete the message ?`,
                confirmText: 'Delete',
                variant: 'danger',
                checkboxLabel: 'Delete for everyone',
            });

            const confirmed = typeof res === 'boolean' ? res : res.confirmed;
            const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

            if (!confirmed || !msg.id) return;

            try {
                await deleteMessage(msg.id, deleteForEveryone);
                removeMessage(msg.id)
                
            } catch (error) {
                console.error('Failed to clear history', error);
            }
            break;
    }
    handleCloseMenu();
}

const { messages, timeline, isChatEmpty, isLastPage, userExists, loadInitial, loadOlder, send, append,removeMessage } = useMessages();
const {
    showGoToBottom, scrollToBottom, scrollToMessageBottom, lastVisibleMessageId,
    isAtTop, isNearBottom, updateGoToBottom, preserveOnPrepend,
} = useMessageScroll(listRef);
const { markRead, cancel: cancelRead } = useReadReceipts((chatId, message) => {
    const readMs = Math.max(lastReadMs(), ts(message));
    chatStore.setReadState(
        chatId,
        message.created_at,
        messages.value.filter((m) => isIncoming(m) && ts(m) > readMs).length,
    );
});

/** Optional fields we may add to `Chat` later (presence, typing). */
type ChatPresence = { is_online?: boolean; is_typing?: boolean };
const presence = computed(() => props.chat as Chat & ChatPresence);
const statusText = computed(() => {
    if (presence.value.is_typing) return 'typing…';
    if (presence.value.is_online) return 'online';
    return '';
});

type Row =
    | { kind: 'date'; key: string; label: string }
    | {
        kind: 'message';
        id?: string;
        key: string | number;
        text: string;
        time: string;
        isSent: boolean;
        attachments?: Attachment[];
    };

const rows = computed<Row[]>(() =>
    timeline.value.map((item, index) =>
        isDateSeparator(item)
            ? { kind: 'date', key: `date-${index}`, label: item.label }
            : {
                kind: 'message',
                id: item.id,
                key: item.id ?? `m-${index}`,
                text: item.content,
                time: item.created_at,
                isSent: messageSenderId(item) === selfId.value,
                attachments: item.attachments,
            },
    ),
);

const hasMessages = computed(() => messages.value.length > 0);

const ts = (m: Message) => new Date(m.created_at).getTime();
const lastReadMs = () => (props.chat.last_read_at ? new Date(props.chat.last_read_at).getTime() : 0);
const isIncoming = (m: Message) => messageSenderId(m) !== selfId.value;
/** Unseen = from the other user and newer than the chat's last_read_at. */
const isUnseen = (m: Message) => isIncoming(m) && ts(m) > lastReadMs();

/** Mark the last visible unseen message as read. */
function readVisible(): void {
    if (props.chat.id == null) return;
    const id = lastVisibleMessageId();
    if (id == null) return;
    let i = messages.value.findIndex((m) => String(m.id) === id);
    while (i >= 0 && !isUnseen(messages.value[i])) i--;
    if (i < 0) return;
    markRead(messages.value[i], props.chat.id);
}

async function openChat(slug: string): Promise<void> {
    cancelRead();
    const previousEl = listRef.value;
    showGoToBottom.value = false;
    await loadInitial(slug);

    if (props.chat.last_read_at) {
        for (let n = 0; n < 50 && !isLastPage.value; n++) {
            const oldest = messages.value[0];
            if (!oldest || ts(oldest) <= lastReadMs()) break;
            if (!(await loadOlder(slug))) break;
        }
    }
    await waitForList(previousEl);

    const first = props.chat.last_read_at ? messages.value.findIndex(isUnseen) : -1;
    if (first > 0) {
        scrollToMessageBottom(messages.value[first - 1].id!);
    } else if (first === 0) {
        listRef.value && (listRef.value.scrollTop = 0);
    } else {
        scrollToBottom();
    }
    updateGoToBottom();
    readVisible();
}

async function waitForList(previous: HTMLElement | null): Promise<void> {
    for (let i = 0; i < 40; i++) {
        if (isChatEmpty.value === true || userExists.value === false) return;
        const el = listRef.value;
        if (el && el !== previous && el.isConnected) break;
        await new Promise((r) => setTimeout(r, 25));
    }
    await nextTick();
}

watch(
    () => props.chat.slug,
    (slug) => {
        if (slug) {
            void openChat(slug);
        }
    },
    { immediate: true },
);

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
    const chatId = props.chat.id;
    if (chatId == null || !messages.value.some(isUnseen)) return;
    const prevReadAt = props.chat.last_read_at;
    const prevCount = props.chat.unseen_messages_count;
    cancelRead();
    chatStore.seenAll(chatId);
    try {
        await markChatAsRead(chatId);
    } catch (err) {
        props.chat.last_read_at = prevReadAt;
        props.chat.unseen_messages_count = prevCount;
        console.error('Failed to mark chat as read', err);
    }
}
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

onBeforeUnmount(cancelRead);

async function handleSend(): Promise<void> {
    const content = draft.value.trim();
    const slug = props.chat.slug;
    if (!slug || userExists.value === false || (!content && attachments.value.length === 0)) {
        return;
    }
    sending.value = true;
    try {
        const message = await send(slug, content, attachments.value);
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
        draft.value = '';
        attachments.value = [];
        if (props.chat.id != null) {
            chatStore.setLastMessage(props.chat.id, message.content, message.created_at);
        }
        await nextTick();
        scrollToBottom(true);
        if (props.chat.id != null) {
            chatStore.seenAll(props.chat.id);
        }
    } catch {
        // Network error
    } finally {
        sending.value = false;
    }
}

type MessageRow = Extract<Row, { kind: 'message' }>;

const groups = computed(() => {
    const out: { key: string; label: string; messages: MessageRow[] }[] = [];
    for (const row of rows.value) {
        if (row.kind === 'date') {
            out.push({ key: row.key, label: row.label, messages: [] });
        } else {
            if (!out.length) out.push({ key: 'start', label: '', messages: [] });
            out[out.length - 1].messages.push(row);
        }
    }
    return out;
});

onKeyStroke('Escape', () => {
    if (hasOpenOverlay.value) return;
    router.push('/');
});

const isDraggingOver = ref(false);

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
                        {{ userExists === false ? 'User does not exist' : `No messages with ${chat.name ?? chat.slug}
                        yet` }}
                    </h2>
                    <p v-if="userExists !== false" class="text-sm text-gray-500 dark:text-gray-400">
                        Say hello to start the conversation.
                    </p>
                </div>

                <!-- Message timeline -->
                <div v-else ref="listRef" role="log" aria-live="polite"
                    class="flex-1 w-full min-h-0 px-1 pt-3 pb-2 overflow-y-auto scrollable" @scroll="handleScroll">
                    <section v-for="group in groups" :key="group.key">
                        <div v-if="group.label" class="sticky z-40 flex justify-center py-1.5 top-1">
                            <span dir="rtl"
                                class="px-3 py-1 text-xs font-medium text-gray-600 rounded-full shadow-sm bg-white/90 backdrop-blur dark:bg-gray-700/90 dark:text-gray-200">
                                {{ group.label }}
                            </span>
                        </div>
                        <MessageBubble v-for="row in group.messages" :key="row.key" :data-message-id="row.id"
                            :message="row.text" :time="row.time" :is-sent="row.isSent" :attachments="row.attachments"
                            :is-menu-open="activeMenuMessage?.key === row.key"
                            @open-menu="handleOpenMessageMenu(row, $event)" />
                    </section>
                </div>
            </div>
        </Transition>

        <MessageComposer v-model="draft" :sending="sending" :attachments="attachments" @send="handleSend" />

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