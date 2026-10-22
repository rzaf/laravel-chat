<script setup lang="ts">
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { getOverlayZIndex, createOverlayId, registerOverlay, unregisterOverlay } from '@/composables/useOverlayManager';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        isOpen: boolean;
        x: number;
        y: number;
        align?: 'left' | 'right';
    }>(),
    {
        align: 'left',
    }
);

const emit = defineEmits<{ (e: 'close'): void }>();

const menuRef = ref<HTMLElement | null>(null);
const posX = ref(props.x);
const posY = ref(props.y);

// Adjust position to prevent viewport overflow and handle orientation
const adjustPosition = async () => {
    if (!props.isOpen) return;

    // Default to the initial coordinates
    posX.value = props.x;
    posY.value = props.y;

    // Wait for the DOM to render the menu so we can measure its dimensions
    await nextTick();
    if (!menuRef.value) return;

    const menuRect = menuRef.value.getBoundingClientRect();
    const windowWidth = window.innerWidth;
    const windowHeight = window.innerHeight;
    const margin = 12; // Safety margin from screen borders

    // If 'right' orientation is requested, shift the X coordinate so the right edge aligns with x
    if (props.align === 'right') {
        posX.value = props.x - menuRect.width;
    }

    // 1. Check right edge overflow -> shift left if needed
    if (posX.value + menuRect.width > windowWidth - margin) {
        posX.value = windowWidth - menuRect.width - margin;
    }

    // 2. Check bottom edge overflow -> shift upward if needed
    if (posY.value + menuRect.height > windowHeight - margin) {
        posY.value = windowHeight - menuRect.height - margin;
    }

    // 3. Ensure it doesn't fall off the left or top edges
    if (posX.value < margin) posX.value = margin;
    if (posY.value < margin) posY.value = margin;

    menuRef.value?.focus();
};

// Re-calculate when the menu opens or coordinates change
watch(
    () => [props.isOpen, props.x, props.y, props.align],
    () => {
        adjustPosition();
    },
    { immediate: true }
);

const overlayId = createOverlayId('context-menu');
const currentZIndex = computed(() => getOverlayZIndex(overlayId));

function closeMenu(): void {
    emit('close');
}

watch(
    () => props.isOpen,
    (isOpen) => {
        if (isOpen) {
            registerOverlay(overlayId, 'context-menu', closeMenu);
        } else {
            unregisterOverlay(overlayId);
        }
    },
    { immediate: true, flush: 'sync' },
);

onBeforeUnmount(() => {
    unregisterOverlay(overlayId);
});
</script>

<template>
    <Teleport to="body">
        <template v-if="isOpen">
            <!-- Full-screen backdrop catcher: closes menu on outside click -->
            <div class="fixed inset-0 w-full h-full" @contextmenu.prevent="emit('close')" @click="emit('close')" :style="{zIndex: currentZIndex}"></div>

            <div
                ref="menuRef"
                tabindex="-1"
                :style="{ top: posY + 'px', left: posX + 'px',  zIndex: currentZIndex+1 }"
                class="fixed flex flex-col bg-gray-50 rounded-lg shadow-lg dark:bg-gray-700 min-w-40"
            >
                <slot />
            </div>
        </template>
    </Teleport>
</template>