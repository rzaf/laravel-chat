import { useDateFormat } from '@vueuse/core';
import type { ChatTimelineItem, Message } from '@/types';

/**
 * Turn a chronological list of messages into a timeline with day dividers
 * inserted whenever the calendar day changes. Pure — safe to use in a computed.
 */
export function groupMessagesByDate(messages: Message[]): ChatTimelineItem[] {
    const timeline: ChatTimelineItem[] = [];
    let lastDay: string | null = null;

    for (const message of messages) {
        const day = useDateFormat(message.created_at, 'YYYY-MM-DD').value;
        if (day !== lastDay) {
            timeline.push({
                type: 'date',
                label: useDateFormat(message.created_at, 'MMMM D, YYYY').value,
            });
            lastDay = day;
        }
        timeline.push(message);
    }

    return timeline;
}
