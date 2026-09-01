<script setup lang="ts">
defineProps<{
    isOpen: boolean;
    x: number;
    y: number;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();
</script>

<template>
    <template v-if="isOpen">
        <!-- Full-screen catcher: any outside click closes the menu. -->
        <div class="fixed inset-0 z-40 w-full h-full" @click="emit('close')"></div>

        <div
            :style="{ top: y + 'px', left: x + 'px' }"
            class="fixed z-50 flex flex-col bg-white rounded-lg shadow-lg dark:bg-gray-700"
        >
            <slot />
        </div>
    </template>
</template>
