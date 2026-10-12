<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import useChatStore from '@/stores/chat';
import ChatListItem from '@/components/chat/ChatListItem.vue';
import MenuIcon from '@/assets/svgs/Menu.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import type { Chat } from '@/types';

const chatStore = useChatStore();

const emit = defineEmits<{
    (e: 'toggleMenu'): void;
}>();

/* ---------- search ---------- */
const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

/* ---------- filters (NEW) ---------- */
type Filter = 'all' | 'unread' | 'files';
const activeFilter = ref<Filter>('all');
const filters: { id: Filter; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'unread', label: 'Unread' },
    // { id: 'files', label: 'Files' },
];

const textOf = (chat: Chat) => [chat.name, chat.slug, chat.last_message].filter(Boolean).join(' ').toLowerCase();
const unreadOf = (chat: Chat): number => chat.unseen_messages_count ?? 0;
// const hasFiles = (chat: Chat): boolean => (chat.latest_message_attachments_count ?? 0) > 0;

const visibleChats = computed(() => {
    const q = query.value.trim().toLowerCase();
    return (chatStore.chatList ?? [])
        .filter((chat: Chat) => {
            if (q && !textOf(chat).includes(q)) return false;
            if (activeFilter.value === 'unread') return unreadOf(chat) > 0;
            // if (activeFilter.value === 'files') return hasFiles(chat);
            return true;
        })
        // pinned chats first (needs a `pinned` field later; no-op until then); sort is stable
        .sort((a: any, b: any) => Number(!!b.pinned) - Number(!!a.pinned));
});

const isEmpty = computed(() => !chatStore.isLoading && (chatStore.chatList ?? []).length === 0);
const noResults = computed(
    () => !chatStore.isLoading && !isEmpty.value && visibleChats.value.length === 0
);

function clearSearch() {
    query.value = '';
    searchInput.value?.focus();
}

/* "/" focuses search, Escape clears it */
function onKey(e: KeyboardEvent) {
    const typing = ['INPUT', 'TEXTAREA'].includes((e.target as HTMLElement)?.tagName);
    if (e.key === '/' && !typing) {
        e.preventDefault();
        searchInput.value?.focus();
    }
    if (e.key === 'Escape' && document.activeElement === searchInput.value) {
        query.value = '';
        searchInput.value?.blur();
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <aside
        class="flex flex-col w-full h-full bg-white border-r border-gray-100 dark:bg-gray-800 dark:border-gray-700 lg:w-96 lg:min-w-96"
    >
        <!-- Top bar -->
        <header class="flex items-center gap-2 px-3 pt-3 pb-2">
            <button
                type="button"
                aria-label="Open menu"
                class="grid transition-colors rounded-full size-10 shrink-0 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click="emit('toggleMenu')"
            >
                <MenuIcon class="size-6 fill-gray-500 dark:fill-gray-300" />
            </button>

            <form class="relative flex-1" role="search" @submit.prevent>
                <SearchIcon class="absolute -translate-y-1/2 pointer-events-none left-3 top-1/2 size-4 text-gray-400" ></SearchIcon>
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    placeholder="Search chats"
                    aria-label="Search chats"
                    class="w-full h-10 pl-9 pr-9 text-sm placeholder-gray-400 transition bg-gray-100 border border-transparent rounded-full outline-none dark:bg-gray-700 dark:text-gray-100 focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                />
                <button
                    v-if="query"
                    type="button"
                    aria-label="Clear search"
                    class="absolute grid text-gray-400 -translate-y-1/2 rounded-full right-2 top-1/2 size-6 place-items-center hover:text-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600"
                    @click="clearSearch"
                >
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </form>

        </header>

        <!-- Filter chips -->
        <div class="flex gap-2 px-3 pb-2" role="tablist" aria-label="Filter chats">
            <button
                v-for="f in filters"
                :key="f.id"
                type="button"
                role="tab"
                :aria-selected="activeFilter === f.id"
                class="px-3 py-1 text-sm transition-colors rounded-full"
                :class="
                    activeFilter === f.id
                        ? 'bg-blue-600 text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                "
                @click="activeFilter = f.id"
            >
                {{ f.label }}
            </button>
        </div>

        <!-- List -->
        <div id="chat-list" class="flex flex-col flex-1 min-h-0 border-t border-gray-100 scrollable dark:border-gray-700">
            <!-- Loading skeleton -->
            <template v-if="chatStore.isLoading">
                <div v-for="n in 6" :key="n" class="flex items-center w-full gap-3 px-3 py-3" aria-hidden="true">
                    <div class="bg-gray-100 rounded-full size-12 shrink-0 dark:bg-gray-700 animate-pulse"></div>
                    <div class="flex-1 space-y-2">
                        <div class="w-2/5 h-4 bg-gray-100 rounded dark:bg-gray-700 animate-pulse"></div>
                        <div class="w-4/5 h-3.5 bg-gray-100 rounded dark:bg-gray-700 animate-pulse"></div>
                    </div>
                </div>
            </template>

            <!-- Empty: no chats yet -->
            <div v-else-if="isEmpty" class="flex flex-col items-center gap-3 px-5 py-8 text-center">
                <p class="font-medium text-gray-800 dark:text-gray-100">No chats yet</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Start a conversation to see it here.</p>
            </div>

            <!-- Empty: filter/search matched nothing -->
            <div v-else-if="noResults" class="px-8 py-16 text-center">
                <p class="font-medium text-gray-800 dark:text-gray-100">No matching chats</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Try a different name or switch the filter back to All.
                </p>
            </div>

            <ChatListItem
                v-for="chat in visibleChats"
                v-else
                :key="chat.id ?? chat.slug ?? ''"
                :chat="chat"
                :current-chat="chatStore.currentChat"
            />
        </div>
    </aside>
</template>