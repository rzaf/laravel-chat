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
import { forwardMessage, joinChat, setChatMute } from '@/api/index.ts';
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
            type: item.type,
            time: item.created_at,
            updatedAt: item.updated_at,
            forwardedFrom: item.forwarded_from,
            isSent: isMine(item),
            senderName: item.sender?.name,
            senderProfile: item.sender?.profile_photo,
            senderSlug: item.sender?.username,
            senderId: item.sender_id,
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
    chatStore.setLastMessage({
        chat_id: chatId,
        content: sent.content || fallbackContent,
        type: sent.type || 'normal',
        created_at: sent.created_at,
    });
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
                        {{ emptyTitle }}
                    </h2>
                    <p v-if="userExists !== false && canSend" class="text-sm text-gray-500 dark:text-gray-400">
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
                        <MessageBubble v-for="row in group.messages" :key="row.key" :chat-type="chat.type"
                            :data-message-id="row.id" :message="row.text" :type="row.type" :time="row.time" :updated-at="row.updatedAt"
                            :is-sent="row.isSent" :sender-name="row.senderName" :sender-profile="row.senderProfile"
                            :sender-slug="row.senderSlug" :sender-id="row.senderId" :attachments="row.attachments"
                            :replyToMessageId="messageOf(row)?.reply_to_id" :reply-to="messageOf(row)?.reply_to"
                            :reply-author="replyAuthorOf(row)" :forwarded-from="messageOf(row)?.forwarded_from"
                            @jump-to-reply="jumpToMessage" :is-menu-open="activeMenuMessage?.key === row.key"
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

        <MessageComposer v-if="canSend" ref="composerRef" v-model="draft" :sending="sending" :attachments="attachments"
            :recording-disabled="!!editingMessage" @send="handleSend" @send-audio="handleSendAudio"
            @send-video="handleSendVideo" />

        <!-- Previewing a public group / channel -->
        <div v-else-if="canJoin"
            class="flex items-center justify-center px-4 py-3 bg-white border-t border-gray-100 dark:bg-gray-800 dark:border-gray-700">
            <button type="button" :disabled="joining"
                class="px-6 py-2 text-sm font-medium text-blue-600 transition-colors rounded-full cursor-pointer hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-500/10 disabled:opacity-50"
                @click="handleJoin">
                {{ joining ? 'Joining…' : chatType === 'channel' ? 'Join channel' : 'Join group' }}
            </button>
        </div>

        <!-- Member who can't post: channel (mute toggle) or read-only group -->
        <div v-else
            class="flex items-center justify-center px-4 py-3 bg-white border-t border-gray-100 dark:bg-gray-800 dark:border-gray-700">
            <button v-if="chatType === 'channel'" type="button"
                class="px-6 py-2 text-sm font-medium text-blue-600 transition-colors rounded-full cursor-pointer hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-500/10"
                @click="toggleMute">
                {{ chat.muted ? 'Unmute' : 'Mute' }}
            </button>
            <p v-else class="text-sm text-gray-500 dark:text-gray-400">Only admins can send messages in this group</p>
        </div>

        <!-- Forward Modal -->
        <ForwardModal ref="forwardModalRef" @forward="handleForwardMessage" />

        <!-- Shared Modern Context Menu -->
        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="handleCloseMenu">
            <ul class="py-1 space-y-0.5 text-sm">
                <li v-if="canSend">
                    <button type="button"
                        class="flex items-center w-full gap-2 px-2 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600/70 rounded-xl transition-colors"
                        @click="handleMenuAction('reply')">
                        <ReplyIcon class="size-4 text-gray-500 dark:text-gray-400 shrink-0" />
                        <span>Reply</span>
                    </button>
                </li>
                <li v-if="canSend && activeMenuMessage?.isSent && activeMenuMessage?.forwardedFrom == null">
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
                <div v-if="isMember" class="my-1 border-t border-gray-100 dark:border-gray-600" />
                <li v-if="isMember">
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