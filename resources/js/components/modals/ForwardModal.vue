<!-- src/components/modals/ForwardModal.vue -->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useModalControl } from '@/composables/useModalControl';
import Modal from '@/components/common/Modal.vue';
import useChatStore from '@/stores/chat';
import type { Chat, Message } from '@/types';
import CloseIcon from '@/assets/svgs/Close.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import Avatar from '@/components/chat/Avatar.vue';

const emit = defineEmits<{
    (e: 'forward', chats: Chat[], message: Message): void;
}>();

const PREVIEW_MAX_CHARS = 140;

const { modal, open, close, toggle, isOpen } = useModalControl();
const chatStore = useChatStore();
const query = ref('');
const messageToForward = ref<Message | null>(null);
/** Chats ticked by the user; survives changes to the search box. */
const selected = ref<Chat[]>([]);

const keyOf = (c: Chat) => String(c.id ?? c.slug);
const isSelected = (c: Chat) => selected.value.some((s) => keyOf(s) === keyOf(c));

function openModal(message: Message) {
    messageToForward.value = message;
    selected.value = [];
    query.value = '';
    open();
}

/** Short, single-purpose preview — never the full (possibly huge) message. */
const previewText = computed(() => {
    const m = messageToForward.value;
    if (!m) return '';
    const text = (m.content ?? '').replace(/\s+/g, ' ').trim();
    if (text) {
        return text.length > PREVIEW_MAX_CHARS ? `${text.slice(0, PREVIEW_MAX_CHARS).trimEnd()}…` : text;
    }
    return m.attachments?.length ? 'Attachment' : '';
});

const filteredChats = computed(() => {
    const q = query.value.toLowerCase().trim();
    const chats = (chatStore.chatList ?? []).filter((c) => c.slug);
    if (!q) return chats;
    return chats.filter(
        (c) =>
            c.name?.toLowerCase().includes(q) ||
            c.slug?.toLowerCase().includes(q) ||
            c.last_message?.toLowerCase().includes(q),
    );
});

function toggleChat(chat: Chat) {
    selected.value = isSelected(chat)
        ? selected.value.filter((s) => keyOf(s) !== keyOf(chat))
        : [...selected.value, chat];
}

function confirmForward() {
    const message = messageToForward.value;
    if (!message || selected.value.length === 0) return;
    emit('forward', [...selected.value], message);
    close();
}

defineExpose({ open: openModal, close, toggle, isOpen, messageToForward });
</script>

<template>
    <Modal ref="modal">
        <div class="flex flex-col h-full">
            <!-- Header -->
            <div class="flex items-center justify-between px-5 pt-4 pb-2">
                <div>
                    <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100">Forward message</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Select one or more chats</p>
                </div>
                <button type="button" aria-label="Close"
                    class="grid transition-colors rounded-full cursor-pointer size-10 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                    @click="close">
                    <CloseIcon class="size-6 text-gray-500 dark:text-gray-300" />
                </button>
            </div>

            <!-- Search -->
            <div class="px-5 pb-3">
                <div class="relative">
                    <SearchIcon
                        class="absolute -translate-y-1/2 pointer-events-none left-3.5 top-1/2 size-4 text-gray-400" />
                    <input v-model="query" type="search" placeholder="Search chats" aria-label="Search chats"
                        class="w-full h-11 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 transition bg-gray-100 border border-transparent outline-none rounded-full dark:bg-gray-700 dark:text-white focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" />
                </div>
            </div>

            <!-- Chat list -->
            <div class="flex flex-col flex-1 min-h-0 px-3 pb-2 overflow-y-auto scrollable" role="listbox"
                aria-multiselectable="true">
                <div v-if="filteredChats.length === 0"
                    class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    No chats found
                </div>
                <button v-for="chat in filteredChats" :key="chat.id ?? chat.slug" type="button" role="option"
                    :aria-selected="isSelected(chat)"
                    class="flex items-center w-full gap-3 px-3 py-2.5 text-left transition-colors cursor-pointer rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700"
                    :class="{ 'bg-blue-50 dark:bg-gray-700/60': isSelected(chat) }" @click="toggleChat(chat)">
                    <Avatar class="size-12 shrink-0" :src="chat.profile_photo || '/storage/default-profile.png'" />
                    <span class="flex flex-col flex-1 min-w-0">
                        <span class="text-base font-medium text-gray-900 truncate dark:text-gray-100">
                            {{ chat.name ?? chat.slug }}
                        </span>
                        <span class="text-sm text-gray-500 truncate dark:text-gray-400">
                            {{ chat.last_message || `@${chat.slug}` }}
                        </span>
                    </span>
                    <!-- check circle -->
                    <span class="grid transition-colors border-2 rounded-full size-6 place-items-center shrink-0"
                        :class="isSelected(chat)
                            ? 'bg-blue-600 border-blue-600'
                            : 'border-gray-300 dark:border-gray-500'">
                        <svg v-if="isSelected(chat)" class="size-4 text-white" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M5 12l5 5L20 7" />
                        </svg>
                    </span>
                </button>
            </div>

            <!-- Footer: selection, short preview, confirm -->
            <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700">
                <div v-if="selected.length" class="flex flex-wrap gap-1.5 mb-2 overflow-y-auto max-h-16">
                    <span v-for="chat in selected" :key="keyOf(chat)"
                        class="inline-flex items-center gap-1 py-0.5 pl-2.5 pr-1 text-xs text-blue-700 bg-blue-100 rounded-full dark:bg-blue-900/40 dark:text-blue-200">
                        <span class="truncate max-w-32">{{ chat.name ?? chat.slug }}</span>
                        <button type="button" :aria-label="`Remove ${chat.name ?? chat.slug}`"
                            class="grid rounded-full cursor-pointer size-4 place-items-center hover:bg-blue-200 dark:hover:bg-blue-800"
                            @click="toggleChat(chat)">
                            <CloseIcon class="size-3" />
                        </button>
                    </span>
                </div>

                <p v-if="previewText"
                    class="pl-3 mb-3 text-sm text-gray-600 break-words border-l-2 border-blue-500 line-clamp-2 dark:text-gray-300">
                    {{ previewText }}
                </p>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ selected.length ? `${selected.length} selected` : 'No chats selected' }}
                    </span>
                    <button type="button" :disabled="selected.length === 0"
                        class="px-5 py-2 text-sm font-medium text-white transition-colors bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-blue-600"
                        @click="confirmForward">
                        Forward{{ selected.length > 1 ? ` to ${selected.length}` : '' }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>