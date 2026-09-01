import { ref } from 'vue';

interface ConfirmState {
    isOpen: boolean;
    message: string;
    onConfirm: (() => void) | null;
}

// Module-level singleton: one confirm dialog, shared across the whole app.
const state = ref<ConfirmState>({ isOpen: false, message: '', onConfirm: null });

/**
 * App-level confirm dialog. `<ConfirmModal>` (mounted once in App.vue) renders
 * `state`; any component can trigger it via `confirm(message, onConfirm)`.
 * Replaces the previous `window.deleteModalRef` global + prop/emit plumbing.
 */
export function useConfirm() {
    function confirm(message: string, onConfirm: () => void): void {
        state.value = { isOpen: true, message, onConfirm };
    }

    function close(): void {
        state.value = { isOpen: false, message: '', onConfirm: null };
    }

    function accept(): void {
        state.value.onConfirm?.();
        close();
    }

    return { state, confirm, accept, close };
}
