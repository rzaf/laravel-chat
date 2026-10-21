import { ref } from 'vue';

export type ConfirmVariant = 'danger' | 'warning' | 'info';

export interface ConfirmOptions {
    title?: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: ConfirmVariant;
    checkboxLabel?: string; // <-- Added
    checkboxDefault?: boolean; // <-- Added
}

interface ConfirmState extends ConfirmOptions {
    isOpen: boolean;
    checkboxValue: boolean; // <-- Added
    resolvePromise: ((value: boolean | { confirmed: boolean; checkbox: boolean }) => void) | null; // Updated return type
}

const state = ref<ConfirmState>({
    isOpen: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    variant: 'danger',
    checkboxLabel: undefined,
    checkboxValue: false,
    resolvePromise: null,
});

export function useConfirm() {
    function confirm(options: string | ConfirmOptions): Promise<boolean | { confirmed: boolean; checkbox: boolean }> {
        const opts: ConfirmOptions = typeof options === 'string' ? { message: options } : options;

        state.value = {
            isOpen: true,
            title: opts.title ?? getDefaultTitle(opts.variant),
            message: opts.message,
            confirmText: opts.confirmText ?? getDefaultConfirmText(opts.variant),
            cancelText: opts.cancelText ?? 'Cancel',
            variant: opts.variant ?? 'danger',
            checkboxLabel: opts.checkboxLabel,
            checkboxValue: opts.checkboxDefault ?? false,
            resolvePromise: null,
        };

        return new Promise((resolve) => {
            state.value.resolvePromise = resolve;
        });
    }

    function accept(): void {
        if (state.value.checkboxLabel) {
            state.value.resolvePromise?.({ confirmed: true, checkbox: state.value.checkboxValue });
        } else {
            state.value.resolvePromise?.(true);
        }
        close();
    }

    function close(): void {
        if (state.value.checkboxLabel) {
            state.value.resolvePromise?.({ confirmed: false, checkbox: false });
        } else {
            state.value.resolvePromise?.(false);
        }
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