<script setup lang="ts">
import { ref, watch, onBeforeUnmount, computed } from 'vue';
import { getOverlayZIndex, createOverlayId, registerOverlay, unregisterOverlay } from '@/composables/useOverlayManager';

const props = withDefaults(
    defineProps<{
        widthClass?: string;
        heightClass?: string;
        onEscape?: () => void;
    }>(),
    {
        widthClass: 'w-11/12! lg:!w-1/2',
        heightClass: 'h-[90%]! lg:!h-1/2',
    },
);

const isModalOpen = ref(false);
const overlayId = createOverlayId('modal');
const currentZIndex = computed(() => getOverlayZIndex(overlayId));

function close(): void {
    isModalOpen.value = false;
}

function handleEscape(): void {
    if (props.onEscape) {
        props.onEscape();
    } else {
        close();
    }
}

watch(
    isModalOpen,
    (open) => {
        if (open) {
            registerOverlay(overlayId, 'modal', handleEscape);
        } else {
            unregisterOverlay(overlayId);
        }
    },
    { flush: 'sync' },
);

onBeforeUnmount(() => unregisterOverlay(overlayId));

defineExpose({
    isOpen: () => isModalOpen.value,
    toggle: () => (isModalOpen.value = !isModalOpen.value),
    open: () => (isModalOpen.value = true),
    close,
});
</script>


<template>
    <Transition enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-2 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100"
        leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 translate-y-0 scale-100"
        leave-to-class="opacity-0 translate-y-2 scale-95">
        <div v-if="isModalOpen" :style="{ zIndex: currentZIndex }"
            class="fixed left-0 right-0 m-auto rounded-lg top-5 lg:inset-0 h-max text-gray-800 bg-white dark:bg-gray-800 dark:text-gray-300"
            :class="[widthClass, heightClass]">
            <slot />
        </div>
    </Transition>

    <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150" leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <div v-if="isModalOpen" :style="{ zIndex: currentZIndex - 1 }"
            class="fixed inset-0 w-full h-full backdrop-blur-sm bg-[#24242484]" @click="close" />
    </Transition>
</template>
