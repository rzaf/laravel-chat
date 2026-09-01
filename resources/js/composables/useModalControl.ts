import { ref } from 'vue';

/** Imperative handle exposed by the base `Modal` component. */
export interface ModalHandle {
    open(): void;
    close(): void;
    toggle(): void;
    isOpen(): boolean;
}

/**
 * Wraps a base `<Modal>` template ref and re-exposes its controls, removing the
 * open/close/toggle/isOpen boilerplate each wrapper modal used to repeat.
 *
 * Usage:
 *   const { modal, ...controls } = useModalControl();
 *   defineExpose(controls);
 *   // <Modal ref="modal"> ... </Modal>
 */
export function useModalControl() {
    const modal = ref<ModalHandle | null>(null);

    return {
        modal,
        open: () => modal.value?.open(),
        close: () => modal.value?.close(),
        toggle: () => modal.value?.toggle(),
        isOpen: () => modal.value?.isOpen() ?? false,
    };
}
