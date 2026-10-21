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

    /** `data-message-id` of the last message whose bottom edge is inside the viewport. */
    function lastVisibleMessageId(): string | null {
        const el = container.value;
        if (!el) return null;
        const bottom = el.getBoundingClientRect().bottom + 2;
        const nodes = el.querySelectorAll<HTMLElement>('[data-message-id]');
        for (let i = nodes.length - 1; i >= 0; i--) {
            if (nodes[i].getBoundingClientRect().bottom <= bottom) {
                return nodes[i].dataset.messageId ?? null;
            }
        }
        return null;
    }

    /** Scroll so the bottom edge of the given message aligns with the viewport bottom. */
    function scrollToMessageBottom(id: string | number): boolean {
        const el = container.value;
        const target = el?.querySelector<HTMLElement>(`[data-message-id="${id}"]`);
        if (!el || !target) return false;
        el.scrollTop += target.getBoundingClientRect().bottom - el.getBoundingClientRect().bottom;
        return true;
    }
    
    return {
        showGoToBottom,
        scrollToBottom,
        isAtTop,
        isNearBottom,
        updateGoToBottom,
        preserveOnPrepend,
        lastVisibleMessageId,
        scrollToMessageBottom,
    };
}
