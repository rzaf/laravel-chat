import { ref } from 'vue';

/** Reactive state + positioning for a right-click / trigger-based popup menu. */
export function useContextMenu() {
    const isOpen = ref(false);
    const x = ref(0);
    const y = ref(0);

    function open(event: MouseEvent): void {
        event.preventDefault();
        x.value = event.clientX;
        y.value = event.clientY;
        isOpen.value = true;
    }

    function close(): void {
        isOpen.value = false;
    }

    return { isOpen, x, y, open, close };
}
