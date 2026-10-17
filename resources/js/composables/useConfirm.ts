import { ref } from 'vue';

export type ConfirmVariant = 'danger' | 'warning' | 'info';

export interface ConfirmOptions {
    title?: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: ConfirmVariant;
}

interface ConfirmState extends ConfirmOptions {
    isOpen: boolean;
    resolvePromise: ((value: boolean) => void) | null;
}

const state = ref<ConfirmState>({
    isOpen: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    variant: 'danger',
    resolvePromise: null,
});

export function useConfirm() {
    /**
     * Triggers the confirm modal and returns a Promise resolving to true (accepted) or false (cancelled).
     */
    function confirm(options: string | ConfirmOptions): Promise<boolean> {
        const opts: ConfirmOptions = typeof options === 'string' ? { message: options } : options;

        state.value = {
            isOpen: true,
            title: opts.title ?? getDefaultTitle(opts.variant),
            message: opts.message,
            confirmText: opts.confirmText ?? getDefaultConfirmText(opts.variant),
            cancelText: opts.cancelText ?? 'Cancel',
            variant: opts.variant ?? 'danger',
            resolvePromise: null,
        };

        return new Promise((resolve) => {
            state.value.resolvePromise = resolve;
        });
    }

    function accept(): void {
        state.value.resolvePromise?.(true);
        close();
    }

    function close(): void {
        state.value.resolvePromise?.(false);
        state.value.isOpen = false;
    }

    return { state, confirm, accept, close };
}

function getDefaultTitle(variant: ConfirmVariant = 'danger'): string {
    switch (variant) {
        case 'danger': return 'Are you sure?';
        case 'warning': return 'Please review';
        case 'info': return 'Confirmation';
    }
}

function getDefaultConfirmText(variant: ConfirmVariant = 'danger'): string {
    switch (variant) {
        case 'danger': return 'Delete';
        case 'warning': return 'Proceed';
        case 'info': return 'Continue';
    }
}