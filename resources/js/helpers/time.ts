import { useDateFormat } from '@vueuse/core';

export function formattedTimestamp(timestamp: string | number | Date): string {
    const today = new Date();
    const inputDate = new Date(timestamp);

    // Check if the date is today
    if (inputDate.toDateString() === today.toDateString()) {
        return useDateFormat(inputDate, 'hh:mm A').value; // format as 'hh:mm A'
    }

    // Check if the date is this week
    const firstDayOfWeek = new Date(today);
    firstDayOfWeek.setDate(today.getDate() - today.getDay()); // Get Sunday of this week
    const lastDayOfWeek = new Date(firstDayOfWeek);
    lastDayOfWeek.setDate(firstDayOfWeek.getDate() + 6); // Get Saturday of this week

    if (inputDate >= firstDayOfWeek && inputDate <= lastDayOfWeek) {
        return useDateFormat(inputDate, 'dddd').value; // format as day of the week (e.g., 'Monday')
    }

    // For older dates, format as 'DD/MM/YYYY'
    return useDateFormat(inputDate, 'DD/MM/YYYY').value;
}