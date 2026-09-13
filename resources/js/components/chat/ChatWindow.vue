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

const props = defineProps<{ chat: Chat }>();

const auth = useAuthStore();
const chatStore = useChatStore();
const selfId = computed(() => auth.user?.id);

const listRef = useTemplateRef<HTMLElement>('listRef');
const draft = ref('');
const attachments = ref<File[]>([]);

const sending = ref(false)

const { messages, timeline, isChatEmpty, userExists, loadInitial, loadOlder, send, append } =
    useMessages();
const { showGoToBottom, scrollToBottom, isAtTop, isNearBottom, updateGoToBottom, preserveOnPrepend } =
    useMessageScroll(listRef);
const { markRead } = useReadReceipts((chatId) => chatStore.decrementUnseen(chatId));

type Row =
    | { kind: 'date'; key: string; label: string }
    | { kind: 'message'; key: string | number; text: string; time: string; isSent: boolean; attachments?: Attachment[]};

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
    console.log('handleSend: ', userExists.value)
    const content = draft.value.trim();
    const slug = props.chat.slug;
    if (
        !slug ||
        userExists.value === false ||
        (!content && attachments.value.length === 0)
    ) {
        return;
    }
    // if (!content || !slug || userExists.value === false) {
    //     return;
    // }
    sending.value = true;
    try {
        const message = await send(slug, content, attachments.value);
        if (props.chat.id == null) {
            chatStore.addChat(message?.chat?.id || 0, slug, message?.chat?.name || slug, content, props.chat.direct_user_id);
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
</script>

<template>
    <div class="flex flex-col h-full min-h-0">

        <div id="top-profile" class="z-10 flex items-center px-2 py-3 align-middle border-b border-gray-200 h-14">
            <RouterLink to="/">
                <BackArrowIcon
                    class="px-2 py-1 mr-2 cursor-pointer lg:hidden rounded-2xl fill-gray-500 hover:bg-slate-300 w-[45px] h-[45px]"
                />
            </RouterLink>

            <img src="/storage/default-profile.png" alt="profile-image" class="w-10 h-10 mr-3" />
            <div class="flex flex-col">
                <h3 class="text-xl font-normal">{{ chat.name ?? chat.slug }}</h3>
                <p class="text-sm">@{{ chat.slug }}</p>
            </div>
        </div>

        <div
            v-show="showGoToBottom"
            class="absolute z-90 flex items-center justify-center w-10 h-10 bg-white rounded-lg shadow-lg cursor-pointer dark:bg-gray-900 left-4 bottom-20"
            @click="scrollToBottom(true)"
        >
            <ChevronDownIcon class="fill-black dark:fill-gray-400 w-8 h-8" />
            <p
                v-show="(chat.unseen_messages_count ?? 0) > 0"
                class="absolute p-1 text-lg text-white -translate-x-1/2 bg-blue-500 rounded-full left-1/2 bottom-8"
            >
                {{ chat.unseen_messages_count }}
            </p>
        </div>

        <!-- Loading: waiting on the first page of messages. -->
        <div
            v-if="userExists === null && !hasMessages"
            class="relative flex items-center justify-center w-full h-full pt-3 bg-gray-200 dark:bg-gray-800"
        >
            <div class="flex items-center justify-center space-x-2">
                <span style="animation-delay: -300ms" class="w-3 h-3 bg-gray-400 rounded-full animate-bounce"></span>
                <span style="animation-delay: -200ms" class="w-3 h-3 bg-gray-400 rounded-full animate-bounce"></span>
                <span style="animation-delay: -100ms" class="w-3 h-3 bg-gray-400 rounded-full animate-bounce"></span>
            </div>
        </div>

        <!-- Empty conversation or unknown user. -->
        <div
            v-else-if="isChatEmpty === true || userExists === false"
            class="flex flex-col items-center justify-center w-full h-full bg-gray-200 dark:bg-gray-800"
        >
            <h2 class="px-3 py-1 text-lg italic font-light bg-gray-300 rounded-lg dark:bg-gray-600 dark:text-gray-300">
                {{ userExists === false ? 'User does not exist' : 'Start Chat' }}
            </h2>
        </div>

        <!-- Message timeline. -->
        <div
            v-else
            ref="listRef"
            class="w-full flex-1 min-h-0 overflow-y-auto pt-3 bg-gray-200 scrollable dark:bg-gray-800 "
            @scroll="handleScroll"
        >
            <template v-for="row in rows" :key="row.key">
                <div v-if="row.kind === 'date'" class="flex items-center justify-center py-1">
                    <span class="px-3 py-1 text-gray-200 bg-gray-700 rounded-lg">{{ row.label }}</span>
                </div>
                <MessageBubble v-else :message="row.text" :time="row.time" :is-sent="row.isSent" :attachments="row.attachments"/>
            </template>
        </div>

        <MessageComposer :sending="sending" v-model="draft" :attachments="attachments" @send="handleSend" />
    </div>
</template>
