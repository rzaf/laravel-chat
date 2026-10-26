import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';

const GO_TO_BOTTOM_THRESHOLD = 150;
const WAIT_FOR_LIST_ATTEMPTS = 40;
const WAIT_FOR_LIST_INTERVAL_MS = 25;

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

    /** Wait for the DOM to render new rows, then scroll to the bottom. */
    async function scrollToBottomAfterRender(smooth = true): Promise<void> {
        await nextTick();
        scrollToBottom(smooth);
    }

    function scrollToTop(): void {
        if (container.value) {
            container.value.scrollTop = 0;
        }
    }

    function isAtTop(): boolean {
        // <= 1 tolerates fractional scroll positions on zoomed / hi-dpi screens.
        return (container.value?.scrollTop ?? 2) <= 1;
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
        if (el && el.scrollHeight !== previousHeight) {
            el.scrollTop = el.scrollHeight - previousHeight;
        }
    }

    /**
     * After switching chats the list element is re-created (keyed transition).
     * Wait until a new element is mounted, or until `isSettled()` says there will be none
     * (empty chat / unknown user).
     */
    async function waitForList(previous: HTMLElement | null, isSettled: () => boolean = () => false): Promise<void> {
        for (let i = 0; i < WAIT_FOR_LIST_ATTEMPTS; i++) {
            if (isSettled()) return;
            const el = container.value;
            if (el && el !== previous && el.isConnected) break;
            await new Promise((r) => setTimeout(r, WAIT_FOR_LIST_INTERVAL_MS));
        }
        await nextTick();
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

    /** Smooth-scroll a message to the center and flash it briefly. */
    function jumpToMessage(id: string | number): void {
        const target = container.value?.querySelector<HTMLElement>(`[data-message-id="${id}"]`);
        if (!target) return;
        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        target.animate(
            [{ backgroundColor: 'rgba(59, 130, 246, 0.25)' }, { backgroundColor: 'transparent' }],
            { duration: 1400, easing: 'ease-out' },
        );
    }

    return {
        showGoToBottom,
        scrollToBottom,
        scrollToBottomAfterRender,
        scrollToTop,
        isAtTop,
        isNearBottom,
        updateGoToBottom,
        preserveOnPrepend,
        waitForList,
        lastVisibleMessageId,
        scrollToMessageBottom,
        jumpToMessage,
    };
}