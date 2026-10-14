import { useDateFormat } from '@vueuse/core';
import { useAppConfigStore } from '@/stores/appConfig';

const DAY_MS = 24 * 60 * 60 * 1000;

function localDayNumber(date: Date): number {
    return Math.floor(
        Date.UTC(
            date.getFullYear(),
            date.getMonth(),
            date.getDate(),
        ) / DAY_MS,
    );
}

/**
 * Formats a timestamp supporting different locales and calendar systems.
 * @param timestamp - The date value to format
 * @param now - Current reference date
 * @param isMessageSeparator - Whether this is for a day grouping separator in chat
 * @param overrideLocale - Optional locale string (e.g., 'fa-IR-u-ca-persian') to manually override store activeLocale
 */
export function formattedTimestamp(
    timestamp: string | number | Date,
    now: Date = new Date(),
    isMessageSeparator: boolean = false,
    overrideLocale?: string
): string {
    const inputDate =
        timestamp instanceof Date
            ? new Date(timestamp.getTime())
            : new Date(timestamp);

    if (Number.isNaN(inputDate.getTime())) {
        return '';
    }

    // Access the Pinia store for activeLocale if no override is supplied
    const appConfigStore = useAppConfigStore();
    const activeLocale = overrideLocale || appConfigStore.activeLocale;

    if (isMessageSeparator) {
        if (inputDate.getFullYear() === now.getFullYear()) {
            return new Intl.DateTimeFormat(activeLocale, {
                month: 'long',
                day: 'numeric',
            }).format(inputDate);
        }
        return new Intl.DateTimeFormat(activeLocale, {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        }).format(inputDate);
    }

    const daysAgo = localDayNumber(now) - localDayNumber(inputDate);

    if (daysAgo === 0) {
        return useDateFormat(inputDate, 'HH:mm').value;
    }

    // if (daysAgo === 1) {
    //     return 'Yesterday';
    // }

    if (daysAgo > 1 && daysAgo < 7) {
        return new Intl.DateTimeFormat(activeLocale, { weekday: 'short' }).format(inputDate);
    }

    return new Intl.DateTimeFormat(activeLocale, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(inputDate);
}