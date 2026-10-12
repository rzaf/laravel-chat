import { useDateFormat } from '@vueuse/core';

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

export function formattedTimestamp(
    timestamp: string | number | Date,
    now: Date = new Date(),
): string {
    const inputDate =
        timestamp instanceof Date
            ? new Date(timestamp.getTime())
            : new Date(timestamp);

    if (Number.isNaN(inputDate.getTime())) {
        return '';
    }

    const daysAgo = localDayNumber(now) - localDayNumber(inputDate);

    if (daysAgo === 0) {
        return useDateFormat(inputDate, 'HH:mm').value;
    }

    if (daysAgo === 1) {
        return 'Yesterday';
    }

    if (daysAgo > 1 && daysAgo < 7) {
        return useDateFormat(inputDate, 'ddd').value;
    }

    // if (inputDate.getFullYear() === now.getFullYear()) {
    //     return useDateFormat(inputDate, 'DD/MM').value;
    // }

    return useDateFormat(inputDate, 'DD/MM/YYYY').value;
}