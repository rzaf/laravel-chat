<script setup lang="ts">
import { useConfirm } from '@/composables/useConfirm';

const { state, accept, close } = useConfirm();
</script>

<template>
    <template v-if="state.isOpen">
        <div class="fixed inset-0 z-[60] w-full h-full backdrop-blur-sm bg-[#24242484]" @click="close"></div>

        <div
            class="fixed z-[61] left-0 right-0 mx-auto top-1/3 w-[90%] max-w-md bg-white rounded-lg shadow-lg dark:bg-gray-800"
        >
            <div class="flex flex-col p-6 text-center">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ state.message || 'Are you sure you want to delete?' }}
                </h2>
                <p class="mt-2 text-gray-500 dark:text-gray-400">This action cannot be undone.</p>

                <div class="flex justify-center gap-4 p-4 mt-4">
                    <button
                        class="px-4 py-2 text-sm font-medium text-white transition-all bg-red-600 rounded-lg hover:bg-red-700 focus:ring-4 focus:ring-red-300 dark:focus:ring-red-800"
                        @click="accept"
                    >
                        Yes, Delete
                    </button>
                    <button
                        class="px-4 py-2 text-sm font-medium text-gray-700 transition-all bg-gray-200 rounded-lg hover:bg-gray-300 focus:ring-4 focus:ring-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:focus:ring-gray-600"
                        @click="close"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </template>
</template>
