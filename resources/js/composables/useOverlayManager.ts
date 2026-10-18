import { computed, readonly, ref } from 'vue';

export type OverlayType = 'modal' | 'context-menu' | 'overlay';

interface OverlayEntry {
    id: string;
    type: OverlayType;
    onEscape: () => void;
}

const BASE_Z_INDEX = 1000;
const Z_INDEX_STEP = 10;

const stack = ref<OverlayEntry[]>([]);
let nextId = 0;

export function createOverlayId(prefix = 'overlay'): string {
    return `${prefix}-${++nextId}`;
}

export function registerOverlay(
    id: string,
    type: OverlayType,
    onEscape: () => void,
): void {
    stack.value = stack.value.filter((item) => item.id !== id);
    stack.value.push({ id, type, onEscape });
}

export function unregisterOverlay(id: string): void {
    stack.value = stack.value.filter((item) => item.id !== id);
}

/**
 * Dynamically computes a z-index for an overlay based on its position in the stack.
 */
export function getOverlayZIndex(id: string): number {
    const index = stack.value.findIndex((item) => item.id === id);
    if (index === -1) return BASE_Z_INDEX;
    return BASE_Z_INDEX + (index * Z_INDEX_STEP);
}

export const hasOpenOverlay = computed(() => stack.value.length > 0);

export const hasOpenModal = computed(() =>
    stack.value.some((item) => item.type === 'modal'),
);

export const hasOpenContextMenu = computed(() =>
    stack.value.some((item) => item.type === 'context-menu'),
);

export const openOverlays = readonly(stack);

export function closeTopOverlay(): void {
    stack.value.at(-1)?.onEscape();
}

if (typeof document !== 'undefined') {
    document.addEventListener(
        'keydown',
        (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || stack.value.length === 0) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            closeTopOverlay();
        },
        true,
    );
}