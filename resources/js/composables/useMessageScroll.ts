import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';

const GO_TO_BOTTOM_THRESHOLD = 150;

/** Scroll helpers for the message list container (no data concerns). */
export function useMessageScroll(container: Ref<HTMLElement | null>) {
    const showGoToBottom = ref(false);

    function distanceFromBottom(): number {
        const el = container.value;
        return el ? el.scrollHeight - el.scrollTop - el.clientHeight : 0;
    }

    function scrollToBottom(smooth = false): void {
        const el = container.value;
        el?.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    function isAtTop(): boolean {
        return (container.value?.scrollTop ?? 1) === 0;
    }

    function isNearBottom(threshold = GO_TO_BOTTOM_THRESHOLD): boolean {
        return distanceFromBottom() <= threshold;
    }

    function updateGoToBottom(): void {
        showGoToBottom.value = distanceFromBottom() > GO_TO_BOTTOM_THRESHOLD;
    }

    /** Keep the viewport steady while older messages are prepended above it. */
    async function preserveOnPrepend(mutate: () => Promise<void>): Promise<void> {
        const el = container.value;
        const previousHeight = el?.scrollHeight ?? 0;
        await mutate();
        await nextTick();
        if (el) {
            el.scrollTop = el.scrollHeight - previousHeight;
        }
    }

    return {
        showGoToBottom,
        scrollToBottom,
        isAtTop,
        isNearBottom,
        updateGoToBottom,
        preserveOnPrepend,
    };
}
