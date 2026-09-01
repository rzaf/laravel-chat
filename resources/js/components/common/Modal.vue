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
        zIndex: 50,
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
    <div
        v-show="isModalOpen"
        :style="{ zIndex }"
        class="fixed left-0 right-0 m-auto rounded-lg top-5 lg:inset-0 w-max h-max text-gray-800 bg-white dark:bg-gray-800 dark:text-gray-300"
        :class="[widthClass, heightClass]"
    >
        <slot />
    </div>

    <div
        v-show="isModalOpen"
        :style="{ zIndex: zIndex - 1 }"
        class="fixed inset-0 w-full h-full backdrop-blur-sm bg-[#24242484]"
        @click="isModalOpen = false"
    ></div>
</template>
