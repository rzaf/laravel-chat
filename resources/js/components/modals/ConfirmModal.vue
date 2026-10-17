<script setup lang="ts">
import { useConfirm, type ConfirmVariant } from '@/composables/useConfirm';
import { onKeyStroke } from '@vueuse/core';

const { state, accept, close } = useConfirm();

// Dynamic styles based on variant
const variantStyles: Record<ConfirmVariant, { button: string; ring: string }> = {
    danger: {
        button: 'bg-red-600 hover:bg-red-700 text-white',
        ring: 'focus:ring-red-300 dark:focus:ring-red-800',
    },
    warning: {
        button: 'bg-amber-600 hover:bg-amber-700 text-white',
        ring: 'focus:ring-amber-300 dark:focus:ring-amber-800',
    },
    info: {
        button: 'bg-blue-600 hover:bg-blue-700 text-white',
        ring: 'focus:ring-blue-300 dark:focus:ring-blue-800',
    },
};

onKeyStroke('Escape', (e) => {
    if (state.value.isOpen) {
        e.stopImmediatePropagation(); // Stops other handlers from catching it
        close();
    }
});

</script>

<template>
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0"
        enter-to-class="opacity-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="state.isOpen" class="fixed inset-0 z-[1000] flex items-center justify-center p-4">
            <!-- Backdrop -->
            <div class="fixed inset-0 w-full h-full backdrop-blur-sm bg-black/50 transition-opacity" @click="close">
            </div>

            <!-- Modal Box -->
            <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100" leave-active-class="transition duration-150 ease-in"
                leave-from-class="scale-100 opacity-100" leave-to-class="scale-95 opacity-0">
                <div
                    class="relative z-[1001] w-full max-w-md bg-white dark:bg-gray-800 rounded-xl shadow-2xl overflow-hidden border border-gray-100 dark:border-gray-700">
                    <div class="flex flex-col p-6 text-center sm:text-left">

                        <!-- Header / Title -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    {{ state.title }}
                                </h3>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                                    {{ state.message }}
                                </p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 mt-6">
                            <button type="button"
                                class="px-4 py-2.5 text-sm font-medium text-gray-700 transition-all bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-4 focus:ring-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:focus:ring-gray-700"
                                @click="close">
                                {{ state.cancelText }}
                            </button>

                            <button type="button"
                                class="px-4 py-2.5 text-sm font-medium transition-all rounded-lg focus:outline-none focus:ring-4 shadow-sm"
                                :class="[variantStyles[state.variant!].button, variantStyles[state.variant!].ring]"
                                @click="accept">
                                {{ state.confirmText }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </Transition>
</template>