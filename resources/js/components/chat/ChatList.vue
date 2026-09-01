<script setup lang="ts">
import useChatStore from '@/stores/chat';
import ChatListItem from '@/components/chat/ChatListItem.vue';

const chatStore = useChatStore();

const emit = defineEmits<{ (e: 'toggleMenu'): void }>();

// setTimeout(()=>{
//     console.log('testing adding chat: ');

//     chatStore.addChat(1234, 'asdfg', 'asdfg', 'test message', 12345)
// }, 5000)
</script>

<template>
    <div class="flex flex-col w-full border-r border-gray-100 dark:border-gray-600 lg:w-96 lg:min-w-96">
        <form
            class="flex items-center w-full px-2 py-2 border-b border-gray-100 dark:border-gray-600 h-14"
            @submit.prevent
        >
            <svg class="h-full mr-2 cursor-pointer fill-gray-500" viewBox="0 0 24 24" @click="emit('toggleMenu')">
                <path
                    d="M1 12a1 1 0 0 1 1-1h20a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1zM1 4a1 1 0 0 1 1-1h20a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1zM1 20a1 1 0 0 1 1-1h20a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1z"
                />
            </svg>
            <input
                type="text"
                placeholder="search chats ..."
                class="w-full h-full px-2 py-1 bg-transparent border border-gray-400 rounded-md outline-none"
            />
        </form>

        <div id="chat-list" class="flex flex-col scrollable">
            <template v-if="chatStore.isLoading">
                <div v-for="n in 3" :key="n" class="flex items-center w-full px-2 py-3 align-middle">
                    <div class="h-12 mr-2 bg-gray-100 rounded-full dark:bg-gray-600 animate-pulse min-w-12"></div>
                    <div class="w-full space-y-2">
                        <div class="w-3/4 h-4 bg-gray-100 rounded dark:bg-gray-600 animate-pulse"></div>
                        <div class="w-11/12 h-5 bg-gray-100 rounded dark:bg-gray-600 animate-pulse"></div>
                    </div>
                </div>
            </template>

            <ChatListItem
                v-for="chat in chatStore.chatList ?? []"
                :key="chat.id ?? chat.slug ?? ''"
                :chat="chat"
                :current-chat="chatStore.currentChat"
            />
        </div>
    </div>
</template>
