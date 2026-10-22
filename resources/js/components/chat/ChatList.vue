<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import useChatStore from '@/stores/chat';
import ChatListItem from '@/components/chat/ChatListItem.vue';
import MenuIcon from '@/assets/svgs/Menu.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import ArchiveBoxIcon from '@/assets/svgs/ArchiveBox.svg';
import type { Chat, MuteDuration } from '@/types';
import { toggleChatPin, setChatMute, toggleChatArchive, clearChatHistory, deleteChat, markMessageAsRead, markChatAsRead } from '@/api/index';
import { useConfirm } from '@/composables/useConfirm';
import router from '@/router';

const emit = defineEmits<{ (e: 'toggleMenu'): void }>();
const chatStore = useChatStore();
const chats = computed<Chat[]>(() => chatStore.chatList ?? []);

/* ---------- filters ---------- */
// Later: append user folders here, e.g.
// { id: `folder:${f.id}`, label: f.name, match: (c) => f.chat_ids.includes(c.id) }
const filters = computed(() => [
    { id: 'all', label: 'All', match: (_c: Chat) => true },
    { id: 'unread', label: 'Unread', match: (c: Chat) => (c.unseen_messages_count ?? 0) > 0 },
    { id: 'online', label: 'Online', match: (c: Chat) => !!c.is_online },
]);
const filterId = ref('all');
const activeFilter = computed(() => filters.value.find((f) => f.id === filterId.value) ?? filters.value[0]);

/* ---------- search ---------- */
const query = ref('');
const q = computed(() => query.value.trim().toLowerCase());
const searchInput = ref<HTMLInputElement | null>(null);

const textOf = (c: Chat) => [c.name, c.slug, c.last_message].filter(Boolean).join(' ').toLowerCase();

function clearSearch() {
    query.value = '';
    searchInput.value?.focus();
}

function onKey(e: KeyboardEvent) {
    const typing = ['INPUT', 'TEXTAREA'].includes((e.target as HTMLElement)?.tagName);
    if (e.key === '/' && !typing) {
        e.preventDefault();
        searchInput.value?.focus();
    }
    if (e.key === 'Escape' && document.activeElement === searchInput.value) {
        e.stopImmediatePropagation();
        query.value = '';
        searchInput.value?.blur();
        return;
    }
    if (e.key === 'Escape' && query.value === '' && inArchive.value) {
        e.stopImmediatePropagation();
        inArchive.value = false;
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    chatStore.clear();
});

/* ---------- archive ---------- */
const inArchive = ref(false);
const archived = computed(() => chats.value.filter((c) => c.archived));
const archivedNames = computed(() => archived.value.map((c) => c.name).filter(Boolean).join(', '));
const archivedUnread = computed(
    () => archived.value.filter((c) => !c.muted && (c.unseen_messages_count ?? 0) > 0).length
);

const showArchiveRow = computed(
    () => !inArchive.value && archived.value.length > 0 && !q.value && activeFilter.value.id === 'all'
);

function openArchive() {
    inArchive.value = true;
    query.value = '';
}

// last archived chat was unarchived -> leave the empty archive view
watch(
    () => archived.value.length,
    (n) => {
        if (n === 0) inArchive.value = false;
    }
);

/* ---------- visible list ---------- */
const visibleChats = computed(() => {
    // archive view: archived only; inbox: non-archived, but searching also reaches the archive
    const pool = inArchive.value ? archived.value : q.value ? chats.value : chats.value.filter((c) => !c.archived);

    return pool
        .filter((c) => (!q.value || textOf(c).includes(q.value)) && (inArchive.value || activeFilter.value.match(c)))
        .sort((a, b) => Number(!!b.pinned) - Number(!!a.pinned));
});

const emptyCopy = computed(() => {
    if (chatStore.isLoading || visibleChats.value.length) return null;
    if (!chats.value.length) return { title: 'No chats yet', hint: 'Start a conversation to see it here.' };
    if (!inArchive.value && !q.value && activeFilter.value.id === 'all' && archived.value.length === chats.value.length)
        return { title: 'All your chats are archived', hint: 'Open Archived above to see them.' };
    return {
        title: 'No matching chats',
        hint: q.value ? 'Try a different name or word.' : 'Nothing here yet. Switch the filter back to All.',
    };
});

/* ---------- actions ---------- */
type Key = 'pinned' | 'archived';
const api = { pinned: toggleChatPin, archived: toggleChatArchive } as const;

async function toggle(chat: Chat, key: Key) {
    if (!chat.id) return;
    try {
        const res = await api[key](chat.id, !chat[key]);
        chat[key] = (res as Record<Key, boolean>)[key];
    } catch (error) {
        console.error(`Failed to update ${key} status`, error);
    }
}

async function handleMute(chat: Chat, duration: MuteDuration) {
    if (!chat.id) return;
    
    try {
        const res = await setChatMute(chat.id, duration);
        chat.muted = res.muted;
        chat.mutedFor = duration;
        chat.muted_until = res.muted_until;
    } catch (error) {
        console.error('Failed to mute', error);
    }
}

async function handleMarkAsRead(chat: Chat) {
    if (!chat.id) return;
    
    try {
        await markChatAsRead(chat.id);
        // handle state/store refresh if needed
        chatStore.seenAll(chat.id);
    } catch (error) {
        console.error('Failed to clear history', error);
    }
}

const { confirm } = useConfirm();


async function handleClearHistory(chat: Chat) {
    const res = await confirm({
        title: 'Clear Chat History',
        message: `Are you sure you want to clear the history for ${chat.name}?`,
        confirmText: 'Clear',
        variant: 'warning',
        checkboxLabel: chat.type === 'direct' ? undefined : 'Delete messages for everyone', // or based on backend rules
    });

    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

    if (!confirmed || !chat.id) return;

    try {
        await clearChatHistory(chat.id, deleteForEveryone);
        // handle state/store refresh if needed
    } catch (error) {
        console.error('Failed to clear history', error);
    }
}

async function handleDelete(chat: Chat) {
    const res = await confirm({
        title: 'Delete Chat',
        message: `Are you sure you want to delete the chat with ${chat.name}?`,
        confirmText: 'Delete',
        variant: 'danger',
        checkboxLabel: 'Delete for everyone',
    });

    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

    if (!confirmed || !chat.id) return;

    try {
        const chatId = chat.id;
        await deleteChat(chatId, deleteForEveryone);
        chatStore.removeChat(chatId); // Assuming you have a remove method in store
        if (chatStore.currentChat?.id == chatId) {
            router.push('/');
        }
    } catch (error) {
        console.error('Failed to delete chat', error);
    }
}

</script>

<template>
    <aside
        class="flex flex-col w-full h-full bg-white border-r border-gray-100 dark:bg-gray-800 dark:border-gray-700 lg:w-96 lg:min-w-96">
        <!-- Top bar -->
        <header class="flex items-center gap-2 px-3 pt-3 pb-2">
            <button type="button" aria-label="Open menu"
                class="grid transition-colors rounded-full size-10 shrink-0 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click="emit('toggleMenu')">
                <MenuIcon class="size-6 fill-gray-500 dark:fill-gray-300" />
            </button>

            <form class="relative flex-1" role="search" @submit.prevent>
                <SearchIcon class="absolute -translate-y-1/2 pointer-events-none left-3 top-1/2 size-4 text-gray-400" />
                <input ref="searchInput" v-model="query" type="text" placeholder="Search chats"
                    aria-label="Search chats"
                    class="w-full h-10 pl-9 pr-9 text-sm placeholder-gray-400 transition bg-gray-100 border border-transparent rounded-full outline-none dark:bg-gray-700 dark:text-gray-100 focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" />
                <button v-if="query" type="button" aria-label="Clear search"
                    class="absolute grid text-gray-400 -translate-y-1/2 rounded-full right-2 top-1/2 size-6 place-items-center hover:text-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600"
                    @click="clearSearch">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        stroke-linecap="round">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </form>
        </header>

        <!-- Archive header -->
        <div v-if="inArchive" class="flex items-center gap-2 px-3 pb-2">
            <button type="button" aria-label="Back to chats"
                class="grid rounded-full size-9 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                @click="inArchive = false">
                <svg class="size-5 text-gray-500 dark:text-gray-300" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 5l-7 7 7 7" />
                </svg>
            </button>
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Archived</h2>
        </div>

        <!-- Filter chips (scroll sideways if folders are added later) -->
        <div v-else class="flex gap-2 px-3 pb-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            role="group" aria-label="Filter chats">
            <button v-for="f in filters" :key="f.id" type="button" :aria-pressed="activeFilter.id === f.id"
                class="px-3 py-1 text-sm transition-colors rounded-full shrink-0 whitespace-nowrap" :class="activeFilter.id === f.id
                        ? 'bg-blue-600 text-white'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                    " @click="filterId = f.id">
                {{ f.label }}
            </button>
        </div>

        <!-- List -->
        <div id="chat-list"
            class="flex flex-col flex-1 pt-1 pb-2 min-h-0 border-t border-gray-100 scrollable dark:border-gray-700">
            <!-- Archive row -->
            <div v-if="showArchiveRow && !chatStore.isLoading" class="px-2 py-0.5">
                <button type="button"
                    class="flex items-center w-full gap-3 px-2.5 py-2.5 text-left text-gray-900 transition-colors outline-none rounded-xl hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-700 focus-visible:ring-2 focus-visible:ring-blue-500"
                    @click="openArchive">
                    <span
                        class="grid text-gray-500 bg-gray-200 rounded-full w-13 h-13 shrink-0 place-items-center dark:bg-gray-600 dark:text-gray-200">
                        <ArchiveBoxIcon class="size-6" />
                    </span>
                    <span class="flex flex-col flex-1 min-w-0 gap-0.5">
                        <span class="flex items-center gap-2">
                            <span class="text-base font-medium truncate">Archived</span>
                            <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">{{ archived.length }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-sm text-gray-500 truncate dark:text-gray-400">{{ archivedNames }}</span>
                            <span v-if="archivedUnread"
                                class="grid px-1.5 ml-auto text-xs font-medium text-white bg-blue-600 rounded-full min-w-5 h-5 place-items-center shrink-0"
                                :aria-label="`${archivedUnread} archived chats with unread messages`">
                                {{ archivedUnread > 99 ? '99+' : archivedUnread }}
                            </span>
                        </span>
                    </span>
                </button>
            </div>

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

            <!-- Empty states -->
            <div v-else-if="emptyCopy" class="px-8 py-12 text-center">
                <p class="font-medium text-gray-800 dark:text-gray-100">{{ emptyCopy.title }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ emptyCopy.hint }}</p>
            </div>

            <template v-else>
                <ChatListItem v-for="chat in visibleChats" :key="chat.id ?? chat.slug ?? ''" :chat="chat"
                    :current-chat="chatStore.currentChat" @pin="toggle($event, 'pinned')"
                    @mute="handleMute" @archive="toggle($event, 'archived')" @markAsRead="handleMarkAsRead" @clearHistory="handleClearHistory"
                    @delete="handleDelete" />
            </template>
        </div>
    </aside>
</template>