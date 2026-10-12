<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import useAuthStore from '@/stores/auth';
import useChatStore from '@/stores/chat';
import { useMessages } from '@/composables/useMessages';
import { useMessageScroll } from '@/composables/useMessageScroll';
import { useReadReceipts } from '@/composables/useReadReceipts';
import { isDateSeparator, messageSenderId } from '@/types';
import type { Attachment, Chat } from '@/types';
import MessageBubble from '@/components/chat/MessageBubble.vue';
import MessageComposer from '@/components/chat/MessageComposer.vue';
import BackArrowIcon from '@/assets/svgs/BackArrow.svg';
import ChevronDownIcon from '@/assets/svgs/ChevronDown.svg';
import Avatar from './Avatar.vue';

const props = defineProps<{ chat: Chat }>();

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

const { messages, timeline, isChatEmpty, userExists, loadInitial, loadOlder, send, append } = useMessages();
const { showGoToBottom, scrollToBottom, isAtTop, isNearBottom, updateGoToBottom, preserveOnPrepend } = useMessageScroll(listRef);
const { markRead } = useReadReceipts((chatId) => chatStore.decrementUnseen(chatId));

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
                key: item.id ?? `m-${index}`,
                text: item.content,
                time: item.created_at,
                isSent: messageSenderId(item) === selfId.value,
                attachments: item.attachments,
            },
    ),
);

const hasMessages = computed(() => messages.value.length > 0);

async function openChat(slug: string): Promise<void> {
    console.log('RUNNING openChat in ChatWindow.vue', props.chat)
    showGoToBottom.value = false;
    await loadInitial(slug);
    await nextTick();
    scrollToBottom();
    markRead(messages.value, selfId.value);
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
    if (isNearBottom()) {
        markRead(messages.value, selfId.value);
    }
    const slug = props.chat.slug;
    if (slug && isAtTop()) {
        await preserveOnPrepend(() => loadOlder(slug).then(() => undefined));
    }
}

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
    } catch {
        // Network error — keep the draft so the user can retry.
    } finally {
        sending.value = false;
    }
}

// Realtime: append messages that belong to this open chat.
watch(
    () => chatStore.lastIncoming,
    (incoming) => {
        if (!incoming || incoming.chat_id !== props.chat.id) {
            return;
        }
        const wasNearBottom = isNearBottom(120);
        append(incoming);
        markRead([incoming], selfId.value);
        if (wasNearBottom) {
            void nextTick(() => scrollToBottom(true));
        }
    },
);

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

</script>

<template>
    <div class="relative flex flex-col h-full min-h-0">
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
                @click="scrollToBottom(true)">
                <ChevronDownIcon class="size-7 fill-gray-700 dark:fill-gray-200" />
                <span v-show="(chat.unseen_messages_count ?? 0) > 0"
                    class="absolute grid px-1.5 text-xs font-medium text-white bg-blue-600 rounded-full -top-2 -right-1 min-w-5 h-5 place-items-center">
                    {{ chat.unseen_messages_count }}
                </span>
            </button>
        </Transition>

        <!-- Loading: waiting on the first page of messages -->
        <div v-if="userExists === null && !hasMessages" class="flex items-center justify-center flex-1">
            <div class="flex items-center justify-center space-x-2" role="status" aria-label="Loading messages">
                <span style="animation-delay: -300ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                <span style="animation-delay: -200ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                <span style="animation-delay: -100ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
            </div>
        </div>

        <!-- Empty conversation or unknown user -->
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
            <!-- <template v-for="row in rows" :key="row.key">
                <div v-if="row.kind === 'date'" class="sticky z-10 flex justify-center py-1.5 top-1">
                    <span
                        class="px-3 py-1 text-xs font-medium text-gray-600 rounded-full shadow-sm bg-white/90 backdrop-blur dark:bg-gray-700/90 dark:text-gray-200">
                        {{ row.label }}
                    </span>
                </div>
                <MessageBubble v-else :message="row.text" :time="row.time" :is-sent="row.isSent"
                    :attachments="row.attachments" />
            </template> -->
            <section v-for="group in groups" :key="group.key">
                <div v-if="group.label" class="sticky z-40 flex justify-center py-1.5 top-1">
                    <span
                        class="px-3 py-1 text-xs font-medium text-gray-600 rounded-full shadow-sm bg-white/90 backdrop-blur dark:bg-gray-700/90 dark:text-gray-200"
                    >
                        {{ group.label }}
                    </span>
                </div>
                <MessageBubble
                    v-for="row in group.messages"
                    :key="row.key"
                    :message="row.text"
                    :time="row.time"
                    :is-sent="row.isSent"
                    :attachments="row.attachments"
                />
            </section>
        </div>

        <MessageComposer v-model="draft" :sending="sending" :attachments="attachments" @send="handleSend" />
    </div>
</template>