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
import { addMessageReaction, removeMessageReaction, forwardMessage, joinChat, setChatMute } from '@/api/index.ts';
import { isDateSeparator } from '@/types';
import type { Chat, ChatType, Message, MessageGroup } from '@/types';
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
const composerRef = useTemplateRef<InstanceType<typeof MessageComposer>>('composerRef');

const draft = ref('');
const attachments = ref<File[]>([]);
const sending = ref(false);
const isDraggingOver = ref(false);
const joining = ref(false);

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
    focusComposer,
});

/* ----------------------------------------------------------- derived data */

/** A chat that isn't in the list yet is a direct-chat placeholder (`{ slug }`). */
const chatType = computed<ChatType>(() => props.chat.type ?? 'direct');
/** `false` while previewing a public group / channel that the user hasn't joined. */
const isMember = computed(() => props.chat.is_member !== false);
const canDeleteMessage = computed(() => isMember.value === true
    && (props.chat.type != 'direct' ? (props.chat.my_role == 'owner' || props.chat.my_role == 'admin' || activeMenuMessage?.value?.isSent) : true)
);
const canJoin = computed(() => !isMember.value);
/** Members only; groups may be read-only, channels only let admins post (backend computes `can_send`). */
const canSend = computed(() => isMember.value && (props.chat.can_send ?? true));
/** Private groups have a random slug — don't show it as a @handle. */
const showHandle = computed(() => chatType.value === 'direct' || !props.chat.is_private);

/** Display name of the other side of the conversation. */
const chatLabel = computed(() => props.chat.name ?? props.chat.slug);
const authorLabel = (m: Message) => {
    if (isMine(m)) return 'You';
    // in a group the author is whoever wrote the message, not the group itself
    if (chatType.value === 'group') return m.sender?.name ?? chatLabel.value;
    return chatLabel.value;
};

const statusText = computed(() => {
    if (props.chat.is_typing) return 'typing…';
    if (chatType.value === 'direct') return props.chat.is_online ? 'online' : '';
    const count = props.chat.members_count;
    if (count == null) return '';
    const noun = chatType.value === 'channel' ? 'subscriber' : 'member';
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
});

const emptyTitle = computed(() => {
    const name = props.chat.name ?? props.chat.slug;
    if (userExists.value === false) return chatType.value === 'direct' ? 'User does not exist' : 'Chat does not exist';
    if (chatType.value === 'channel') return 'No posts yet';
    if (chatType.value === 'group') return `No messages in ${name} yet`;
    return `No messages with ${name} yet`;
});


/** Timeline grouped into date sections with consecutive author detection. */
const groups = computed(() => {
    const out: Array<{ key: string; label: string; messages: Array<any> }> = [];

    timeline.value.forEach((item, index) => {
        if (isDateSeparator(item)) {
            out.push({ key: `date-${index}`, label: item.label, messages: [] });
            return;
        }
        if (!out.length) out.push({ key: 'start', label: '', messages: [] });

        const currentGroup = out[out.length - 1];
        const prevItem = timeline.value[index - 1];
        const nextItem = timeline.value[index + 1];

        // First message of a run: previous item is missing, a date separator, or another author
        const isFirst = !(
            prevItem &&
            !isDateSeparator(prevItem) &&
            prevItem.sender_id === item.sender_id
        );

        // Determine if next message belongs to the same sender on the same date separator interval
        const isConsecutive = !!(
            nextItem &&
            !isDateSeparator(nextItem) &&
            nextItem.sender_id === item.sender_id
        );

        currentGroup.messages.push({
            id: String(item.id),
            key: item.id ?? `m-${index}`,
            text: item.content,
            type: item.type,
            time: item.created_at,
            updatedAt: item.updated_at,
            forwardedFrom: item.forwarded_from,
            reactions: item.reactions,
            isSent: chatStore.currentChat?.type !== 'channel' && isMine(item),
            isFirst: chatStore.currentChat?.type !== 'channel' && isFirst,
            isConsecutive: chatStore.currentChat?.type !== 'channel' && isConsecutive,
            senderName: item.sender?.name,
            // own messages fall back to the logged-in user's picture
            senderProfile: item.sender?.profile_photo
                ?? (isMine(item) ? (auth.user as { profile_photo?: string } | null)?.profile_photo : null)
                ?? (chatStore.currentChat?.type == 'direct' ? (chatStore.currentChat?.profile_photo) : undefined),
            senderSlug: item.sender?.username ?? (isMine(item) ? (auth.user?.username ?? null) : undefined),
            senderId: item.sender_id,
            attachments: item.attachments,
            attachments_count: item.attachments_count,
        });
    });
    return out;
});

const messageRuns = computed(() => groups.value.map(group => {
    const runs: any[] = [];
    group.messages.forEach(row => {
        const last = runs[runs.length - 1];
        if (last && last.senderId === row.senderId && last.isSent === row.isSent) {
            last.messages.push(row);
        } else {
            runs.push({ key: row.key, senderId: row.senderId, senderProfile: row.senderProfile, senderSlug: row.senderSlug, isSent: row.isSent, messages: [row] });
        }
    });
    return { ...group, runs };
}));

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




const showAllEmojis = ref(false);

const COMMON_EMOJIS = ['👍', '❤️', '😂', '🔥', '🥰', '👏', '😮', '🙏'];
const EXTRA_EMOJIS = [
    '🎉', '😍', '🤩', '🙌', '💯', '👀', '💀', '😭',
    '😎', '🥳', '🤔', '💪', '✨', '🚀', '⭐', '❤️‍🔥',
    '😘', '🤭', '🤫', '🤲', '😢', '💩',
];

const reactionList = computed(() => {
    const available = showAllEmojis.value ? [...COMMON_EMOJIS, ...EXTRA_EMOJIS] : COMMON_EMOJIS;
    const existing = Object.keys(activeMenuMessage.value?.reactions?.counts ?? {});
    return [...new Set([...available, ...existing])];
});

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

    await loadInitial(slug, chatType.value);
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
function updateLastMessage(chatId: number | null | undefined, sent: Message, fallbackContent = ''): void {
    if (chatId == null) return;
    sent.chat_id = chatId;
    sent.content = sent.content || fallbackContent;
    sent.type = sent.type || 'normal';
    chatStore.setLastMessage(sent);
}

async function handleSend(): Promise<void> {
    const content = draft.value.trim();
    const slug = props.chat.slug;
    if (!slug || !canSend.value || userExists.value === false || (!content && attachments.value.length === 0)) {
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
                chatType.value,
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

/** Send a recorded audio/video message as a standalone attachment. */
async function handleSendRecording(file: File, kind: 'audio' | 'video', onResult: (sent: boolean) => void): Promise<void> {
    const slug = props.chat.slug;
    if (!slug || !canSend.value || userExists.value === false || sending.value || editingMessage.value) {
        onResult(false);
        return;
    }

    // const fallbackContent = kind === 'audio' ? 'Voice message' : 'Video message';
    sending.value = true;
    let sendConfirmed = false;
    try {
        const replyToId = replyingToMessage.value?.id != null ? Number(replyingToMessage.value.id) : null;
        const message = await send(slug, '', [file], replyToId, null, kind);
        sendConfirmed = true;
        onResult(true);
        const messageChatId = props.chat.id ?? message?.chat?.id;

        // First message of a brand-new chat: register it in the list.
        if (props.chat.id == null) {
            chatStore.addChat(
                message?.chat?.id || 0,
                slug,
                message?.chat?.name || slug,
                '',
                props.chat.direct_user_id,
                chatType.value,
                kind,
            );
            chatStore.selectChatBySlug(slug);
        }

        replyingToMessage.value = null;
        updateLastMessage(messageChatId, message);
        await scrollToBottomAfterRender();
        if (messageChatId != null) chatStore.seenAll(messageChatId);
    } catch (error) {
        console.error(`Failed to send ${kind} message`, error);
        // Keep the recording available only when the API request itself failed.
        if (!sendConfirmed) onResult(false);
    } finally {
        sending.value = false;
    }
}

function handleSendAudio(file: File, onResult: (sent: boolean) => void): Promise<void> {
    return handleSendRecording(file, 'audio', onResult);
}

function handleSendVideo(file: File, onResult: (sent: boolean) => void): Promise<void> {
    return handleSendRecording(file, 'video', onResult);
}

const reactionBusy = ref(false);

async function handleReaction(rowId: string | number | undefined, reaction: string): Promise<void> {
    if (rowId == null || reactionBusy.value) return;

    const cachedMessage = messagesById.value.get(String(rowId));
    if (!cachedMessage) return;

    reactionBusy.value = true;
    try {
        // Selecting your current reaction removes it; choosing another replaces it.
        const updatedMessage = cachedMessage.reactions?.my_reaction === reaction
            ? await removeMessageReaction(rowId, reaction)
            : await addMessageReaction(rowId, reaction);

        const currentMessage = messagesById.value.get(String(rowId));
        if (currentMessage) {
            currentMessage.reactions = updatedMessage.reactions;
        }
        // The context menu receives a lightweight timeline row, so keep that
        // snapshot in sync too while the menu remains open.
        if (activeMenuMessage.value && String(activeMenuMessage.value.id) === String(rowId)) {
            activeMenuMessage.value.reactions = updatedMessage.reactions;
        }
    } catch (error) {
        console.error('Failed to update reaction', error);
    } finally {
        reactionBusy.value = false;
    }
}

/* ---------------------------------------------------------------- forward */

async function handleForwardMessage(targetChats: Chat[], msgToForward: Message): Promise<void> {
    if (msgToForward.id == null) return;
    const targets = targetChats.filter((c) => c.id != null && c.can_send !== false);
    if (targets.length === 0) return;

    try {
        const chatIds = targets.map((c) => Number(c.id));
        await forwardMessage(Number(msgToForward.id), chatIds);

        targets.forEach((chat) => {
            updateLastMessage(chat.id, {
                content: msgToForward.content ?? '',
                created_at: new Date().toISOString(),
            });
        });
        if (targets.some((c) => c.slug === props.chat.slug)) {
            await scrollToBottomAfterRender();
        }
        if (targets.length === 1 && targets[0].slug !== props.chat.slug) {
            router.push(`/chats/${targets[0].slug}`);
        }
    } catch (error) {
        console.error('Failed to forward message', error);
    }
}

/* ------------------------------------------------------------ join / mute */

/** Join the public group / channel being previewed. */
async function handleJoin(): Promise<void> {
    const chatId = props.chat.id;
    if (chatId == null || joining.value) return;
    joining.value = true;
    try {
        const joined = await joinChat(chatId);
        // puts it in the chat list and swaps the preview for the real chat
        chatStore.upsertChat(joined);
    } catch (error) {
        console.error('Failed to join chat', error);
    } finally {
        joining.value = false;
    }
}

/** Channels (read-only for most people) show Mute / Unmute instead of the composer. */
async function toggleMute(): Promise<void> {
    const target = props.chat;
    if (target.id == null) return;
    const duration = target.muted ? null : 'forever';
    try {
        const res = await setChatMute(target.id, duration);
        target.muted = res.muted;
        target.mutedFor = duration;
        target.muted_until = res.muted_until;
    } catch (error) {
        console.error('Failed to update mute', error);
    }
}

/* ------------------------------------------------------------ drag & drop */

function dragHasFiles(e: DragEvent): boolean {
    return Array.from(e.dataTransfer?.types ?? []).includes('Files');
}

function handleDragOver(e: DragEvent): void {
    e.preventDefault();
    isDraggingOver.value = dragHasFiles(e) && canSend.value && !editingMessage.value;
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
    if (!canSend.value || editingMessage.value) return;
    const files = e.dataTransfer?.files;
    // The composer sends images through the crop modal first, other files straight to the tray.
    if (files && files.length > 0) {
        composerRef.value?.addFiles(Array.from(files));
    }
}

/* ------------------------------------------------- lifecycle & listeners */

function focusComposer(): void {
    composerRef.value?.focus();
}

watch(
    () => props.chat.slug,
    (slug, previousSlug) => {
        if (previousSlug !== undefined && slug !== previousSlug) {
            composerRef.value?.cancelRecording();
        }
        resetComposerContext();
        focusComposer();
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
    if (hasOpenOverlay.value || composerRef.value?.isCropping) return;
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
            class="absolute inset-0 z-51 flex items-center justify-center m-3 border-2 border-dashed border-blue-500 pointer-events-none bg-blue-500/10 backdrop-blur-sm rounded-3xl">
            <div
                class="flex items-center gap-2 px-6 py-3 font-medium text-blue-600 shadow-xl bg-white/95 dark:bg-gray-800/95 dark:text-blue-400 rounded-2xl ring-1 ring-black/5">
                <span>Drop images or files here to attach</span>
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
                class="flex items-center min-w-0 gap-3 py-1 pl-1.5 pr-3 text-left transition-colors cursor-pointer rounded-2xl hover:bg-gray-100/80 dark:hover:bg-white/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click="emit('openChatProfile', chat)">
                <span class="relative shrink-0">
                    <Avatar class="size-10 shadow-sm" :src="chat.profile_photo || '/storage/default-profile.png'" />
                    <span v-if="chatType === 'direct' && chat.is_online" aria-hidden="true"
                        class="absolute bottom-0 right-0 block rounded-full size-3 bg-emerald-500 ring-2 ring-white dark:ring-gray-900" />
                </span>
                <span class="flex flex-col min-w-0">
                    <span class="text-[15px] font-semibold leading-tight text-gray-900 truncate dark:text-gray-50">
                        {{ chat.name ?? chat.slug }}
                    </span>
                    <span
                        class="flex items-center gap-1.5 text-sm leading-tight text-gray-500 truncate dark:text-gray-400">
                        <span v-if="showHandle" class="truncate">@{{ chat.slug }}</span>
                        <template v-if="statusText">
                            <span v-if="showHandle" aria-hidden="true">·</span>
                            <span
                                :class="chatType === 'direct' || chat.is_typing ? 'text-blue-600 dark:text-blue-400' : ''">
                                {{ statusText }}
                            </span>
                        </template>
                    </span>
                </span>
            </button>
        </header>

        <!-- Jump to latest -->
        <Transition enter-from-class="opacity-0 translate-y-2" enter-active-class="transition duration-150"
            leave-to-class="opacity-0 translate-y-2" leave-active-class="transition duration-150">
            <button v-show="showGoToBottom" type="button" aria-label="Scroll to latest message"
                class="absolute z-30 grid transition-colors bg-white rounded-full shadow-lg cursor-pointer ring-1 ring-black/5 left-4 bottom-24 size-11 place-items-center dark:bg-gray-800 dark:ring-white/10 hover:bg-gray-50 dark:hover:bg-gray-700"
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
                    <Avatar class="mb-3 shadow-xl size-24"
                        :src="chat.profile_photo || '/storage/default-profile.png'" />
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                        {{ emptyTitle }}
                    </h2>
                    <p v-if="userExists !== false && canSend" class="text-sm text-gray-500 dark:text-gray-400">
                        Say hello to start the conversation.
                    </p>
                </div>

                <!-- Message timeline -->
                <div v-else ref="listRef" role="log" aria-live="polite"
                    class="flex-1 w-full min-h-0 px-2 pt-3 pb-3 overflow-y-auto sm:px-5 scrollable"
                    @scroll="handleScroll">
                    <section v-for="group in messageRuns" :key="group.key">
                        <div v-if="group.label" class="sticky z-40 w-fit mx-auto flex justify-center py-1.5 top-1">
                            <span dir="rtl"
                                class="px-3 py-1 text-xs font-medium text-gray-600 rounded-full shadow-sm bg-white/90 dark:bg-gray-700/90 dark:text-gray-200">{{
                                    group.label }}</span>
                        </div>
                        <div v-for="run in group.runs" :key="run.key" class="flex items-end mb-3"
                            :class="run.isSent ? 'flex-row-reverse' : 'flex-row'">
                            <div v-if="chat.type !== 'channel'" class="sticky bottom-0 self-end shrink-0 size-9">
                                <Avatar class="rounded-full size-9 shadow-md"
                                    :src="run.senderProfile || '/storage/default-profile.png'" :asdf="run.senderId"
                                    @click="emit('openChatProfile', chatStore.chatList?.find(c => c.direct_user_id === run.senderId) ?? <Chat>{ slug: run.senderSlug })" />
                            </div>
                            <div class="flex flex-col flex-1 min-w-0 gap-1">
                                <MessageBubble v-for="row in run.messages" :key="row.key" :chat-type="chat.type"
                                    :data-message-id="row.id" :message="row.text" :type="row.type" :time="row.time"
                                    :updated-at="row.updatedAt" :is-sent="row.isSent" :is-first="row.isFirst"
                                    :is-consecutive="row.isConsecutive" :sender-name="row.senderName"
                                    :sender-profile="row.senderProfile" :sender-slug="row.senderSlug"
                                    :sender-id="row.senderId" :attachments="row.attachments"
                                    :replyToMessageId="messageOf(row)?.reply_to_id" :reply-to="messageOf(row)?.reply_to"
                                    :reply-author="replyAuthorOf(row)" :forwarded-from="messageOf(row)?.forwarded_from"
                                    :reactions="messageOf(row)?.reactions"
                                    :is-menu-open="activeMenuMessage?.key === row.key" @jump-to-reply="jumpToMessage"
                                    @open-menu="handleOpenMessageMenu(row, $event)"
                                    @react="handleReaction(messageOf(row)?.id, $event)" />
                            </div>
                        </div>
                    </section>
                </div>

            </div>
        </Transition>

        <!-- Reply Preview Banner -->
        <div v-if="replyingToMessage"
            class="flex items-center justify-between px-4 py-2.5 border-t border-gray-200/70 bg-white/90 backdrop-blur-xl dark:bg-gray-900/90 dark:border-white/10">
            <div class="flex items-center gap-3 min-w-0">
                <ReplyIcon class="size-5 text-blue-600 dark:text-blue-400 shrink-0" />
                <div class="flex flex-col min-w-0 pl-2.5 border-l-[3px] border-blue-500 rounded-xs">
                    <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 truncate">
                        Replying to {{ replyPreviewAuthor }}
                    </span>
                    <span class="text-sm text-gray-700 dark:text-gray-200 truncate">
                        {{ replyPreviewText }}
                    </span>
                </div>
            </div>
            <button type="button" aria-label="Cancel reply"
                class="grid transition-colors rounded-full cursor-pointer size-8 place-items-center hover:bg-gray-100 dark:hover:bg-white/10"
                @click="replyingToMessage = null">
                <CloseIcon class="size-4 text-gray-500" />
            </button>
        </div>

        <!-- Edit Preview Banner -->
        <div v-if="editingMessage"
            class="flex items-center justify-between px-4 py-2.5 border-t bg-amber-50/90 backdrop-blur-xl border-amber-200/70 dark:bg-amber-950/40 dark:border-amber-800/40">
            <div class="flex items-center gap-3 min-w-0">
                <EditIcon class="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
                <div class="flex flex-col min-w-0 pl-2.5 border-l-[3px] border-amber-500 rounded-xs">
                    <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 truncate">
                        Edit message
                    </span>
                    <span class="text-sm text-gray-700 dark:text-gray-200 truncate">
                        {{ editingMessage.content }}
                    </span>
                </div>
            </div>
            <button type="button" aria-label="Cancel edit"
                class="grid transition-colors rounded-full cursor-pointer size-8 place-items-center hover:bg-amber-100 dark:hover:bg-amber-900/50"
                @click="cancelEdit">
                <CloseIcon class="size-4 text-gray-500" />
            </button>
        </div>

        <MessageComposer v-if="canSend" ref="composerRef" v-model="draft" :sending="sending" :attachments="attachments"
            :recording-disabled="!!editingMessage" @send="handleSend" @send-audio="handleSendAudio"
            @send-video="handleSendVideo" />

        <!-- Previewing a public group / channel -->
        <div v-else-if="canJoin"
            class="flex items-center justify-center px-4 py-3 border-t border-gray-200/70 bg-white/90 backdrop-blur-xl dark:bg-gray-900/90 dark:border-white/10">
            <button type="button" :disabled="joining"
                class="px-7 py-2 text-sm font-semibold text-blue-600 transition-colors rounded-full cursor-pointer bg-blue-50 hover:bg-blue-100 dark:text-blue-300 dark:bg-blue-500/15 dark:hover:bg-blue-500/25 disabled:opacity-50"
                @click="handleJoin">
                {{ joining ? 'Joining…' : chatType === 'channel' ? 'Join channel' : 'Join group' }}
            </button>
        </div>

        <!-- Member who can't post: channel (mute toggle) or read-only group -->
        <div v-else
            class="flex items-center justify-center px-4 py-3 border-t border-gray-200/70 bg-white/90 backdrop-blur-xl dark:bg-gray-900/90 dark:border-white/10">
            <button v-if="chatType === 'channel'" type="button"
                class="px-7 py-2 text-sm font-semibold text-blue-600 transition-colors rounded-full cursor-pointer bg-blue-50 hover:bg-blue-100 dark:text-blue-300 dark:bg-blue-500/15 dark:hover:bg-blue-500/25"
                @click="toggleMute">
                {{ chat.muted ? 'Unmute' : 'Mute' }}
            </button>
            <p v-else class="text-sm text-gray-500 dark:text-gray-400">Only admins can send messages in this group</p>
        </div>

        <!-- Forward Modal -->
        <ForwardModal ref="forwardModalRef" @forward="handleForwardMessage" />

        <!-- Shared Modern Context Menu -->
        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="handleCloseMenu">
            <!-- Reaction picker and current totals -->
            <div class="w-56 max-w-[calc(100vw-2rem)] px-2 pt-2 pb-2 border-b border-gray-100 dark:border-gray-700/60">
                <div v-if="Object.keys(activeMenuMessage?.reactions?.counts ?? {}).length"
                    class="flex flex-wrap gap-1 mb-2">
                    <button v-for="(count, emoji) in activeMenuMessage?.reactions?.counts" :key="emoji" type="button"
                        :disabled="reactionBusy"
                        :title="`${count} reaction${count === 1 ? '' : 's'} · click to toggle yours`"
                        class="inline-flex items-center gap-1 px-1.5 py-1 text-[11px] rounded-full transition-colors cursor-pointer disabled:opacity-50"
                        :class="activeMenuMessage?.reactions?.my_reaction === emoji
                            ? 'bg-blue-100 text-blue-700 ring-1 ring-blue-300 dark:bg-blue-500/20 dark:text-blue-300 dark:ring-blue-500/40'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                        @click="handleReaction(activeMenuMessage?.id, String(emoji))">
                        <span class="text-[13px] leading-none">{{ emoji }}</span>
                        <span class="font-medium tabular-nums">{{ count }}</span>
                    </button>
                </div>

                <div class="flex items-center justify-between px-1 mb-1.5">
                    <span class="text-[11px] font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        React
                    </span>
                    <button type="button"
                        class="text-[11px] font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                        @click.stop="showAllEmojis = !showAllEmojis">
                        {{ showAllEmojis ? 'Less' : 'More' }}
                    </button>
                </div>

                <div class="grid grid-cols-6 gap-1">
                    <button v-for="emoji in reactionList" :key="emoji" type="button" :disabled="reactionBusy"
                        :aria-label="`React with ${emoji}`"
                        :aria-pressed="activeMenuMessage?.reactions?.my_reaction === emoji"
                        class="grid size-7 place-items-center text-base rounded-lg transition-colors cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40"
                        :class="activeMenuMessage?.reactions?.my_reaction === emoji
                            ? 'bg-blue-100 ring-1 ring-blue-300 dark:bg-blue-500/20 dark:ring-blue-500/50'
                            : ''" @click="handleReaction(activeMenuMessage?.id, emoji); handleCloseMenu()">
                        {{ emoji }}
                    </button>
                </div>
            </div>

            <ul class="py-1 space-y-0.5 text-sm">
                <li v-if="canSend">
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('reply')">
                        <ReplyIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Reply</span>
                    </button>
                </li>
                <li v-if="canSend && activeMenuMessage?.isSent && activeMenuMessage?.forwardedFrom == null">
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('edit')">
                        <EditIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Edit</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('forward')">
                        <ForwardIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Forward</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('copy')">
                        <CopyIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Copy Text</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('select')">
                        <CircleCheckIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Select</span>
                    </button>
                </li>
                <div v-if="canDeleteMessage" class="my-1 border-t border-gray-100 dark:border-gray-600" />
                <li v-if="canDeleteMessage">
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 rounded-xl transition-colors cursor-pointer"
                        @click="handleMenuAction('delete', props.chat.type === 'direct')">
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