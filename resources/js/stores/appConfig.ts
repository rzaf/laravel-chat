import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export type CalendarSystem = 'gregorian' | 'persian' | 'islamic' | 'buddhist' | 'chinese' | 'japanese';
export type NumberingSystem = 'default' | 'latn'; // 'default' uses native digits (e.g. ۱۴۰۵), 'latn' uses 1405


export const useAppConfigStore = defineStore('appConfig', () => {
    // Get browser defaults as a smart fallback
    const browserLocale = navigator.language || 'en-US';
    const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    const language = ref<string>(browserLocale);
    const timezone = ref<string>(browserTimeZone);
    const calendar = ref<CalendarSystem | 'system'>('system');
    const numberingSystem = ref<NumberingSystem>('latn'); // Default to English digits for clean rendering

    console.log('default browser settings. language:', language.value, 'timezone:', timezone.value, 'calendar:', calendar.value);

    // Resolved calendar format tag for Intl (e.g., 'fa-IR-u-ca-persian')
    const activeLocale = computed(() => {
        let localeStr = language.value;

        // Map calendar types to Unicode extensions
        if (calendar.value !== 'system') {
            const calendarMap: Record<CalendarSystem, string> = {
                gregorian: 'gregory',
                persian: 'persian',
                islamic: 'islamic-umalqura',
                buddhist: 'buddhist',
                chinese: 'chinese',
                japanese: 'japanese',
            };
            localeStr += `-u-ca-${calendarMap[calendar.value]}`;
        }

        // Force Latin/English digits if specified
        if (numberingSystem.value === 'latn') {
            // Append or join extension
            localeStr += localeStr.includes('-u-') ? `-nu-latn` : `-u-nu-latn`;
        }

        return localeStr;
    });

    function setCalendar(newCalendar: CalendarSystem | 'system') {
        calendar.value = newCalendar;
    }

    function setLanguage(newLang: string) {
        language.value = newLang;
    }

    function setTimezone(newTz: string) {
        timezone.value = newTz;
    }

    return {
        language,
        timezone,
        calendar,
        activeLocale,
        setCalendar,
        setLanguage,
        setTimezone,
    };
});