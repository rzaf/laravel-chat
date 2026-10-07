<script setup lang="ts">
import { ref } from 'vue';

withDefaults(
    defineProps<{
        widthClass?: string;
        heightClass?: string;
        zIndex?: number;
    }>(),
    {
        widthClass: 'w-11/12! lg:!w-1/2',
        heightClass: 'h-[90%]! lg:!h-1/2',
        zIndex: 100,
    },
);

const isModalOpen = ref(false);

defineExpose({
    isOpen: () => isModalOpen.value,
    toggle: () => (isModalOpen.value = !isModalOpen.value),
    open: () => (isModalOpen.value = true),
    close: () => (isModalOpen.value = false),
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-2 scale-95"
        enter-to-class="opacity-100 translate-y-0 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 translate-y-0 scale-100"
        leave-to-class="opacity-0 translate-y-2 scale-95"
    >
        <div
            v-if="isModalOpen"
            :style="{ zIndex }"
            class="fixed left-0 right-0 m-auto rounded-lg top-5 lg:inset-0 w-max h-max text-gray-800 bg-white dark:bg-gray-800 dark:text-gray-300"
            :class="[widthClass, heightClass]"
        >
            <slot />
        </div>
    </Transition>

    <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="isModalOpen"
            :style="{ zIndex: zIndex - 1 }"
            class="fixed inset-0 w-full h-full backdrop-blur-sm bg-[#24242484]"
            @click="isModalOpen = false"
        />
    </Transition>
</template>
