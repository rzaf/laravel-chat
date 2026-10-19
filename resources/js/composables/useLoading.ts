import { readonly, ref } from 'vue';

/** Don't show the loader at all if navigation finishes faster than this. */
const SHOW_DELAY_MS = 200;
/** Once shown, keep it visible at least this long to avoid flicker. */
const MIN_VISIBLE_MS = 400;

const isLoading = ref(false);

let showTimer: ReturnType<typeof setTimeout> | undefined;
let hideTimer: ReturnType<typeof setTimeout> | undefined;
let shownAt = 0;

function start(): void {
    clearTimeout(hideTimer);
    hideTimer = undefined;
    if (isLoading.value || showTimer) return;

    showTimer = setTimeout(() => {
        showTimer = undefined;
        shownAt = Date.now();
        isLoading.value = true;
    }, SHOW_DELAY_MS);
}

function stop(): void {
    // Finished before the delay elapsed: the loader was never shown.
    if (showTimer) {
        clearTimeout(showTimer);
        showTimer = undefined;
        return;
    }
    if (!isLoading.value || hideTimer) return;

    const remaining = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt));
    hideTimer = setTimeout(() => {
        hideTimer = undefined;
        isLoading.value = false;
    }, remaining);
}

export function useLoading() {
    return {
        isLoading: readonly(isLoading), // consumers can't mutate it directly
        start,
        stop,
    };
}