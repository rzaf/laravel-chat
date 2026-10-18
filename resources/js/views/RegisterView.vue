<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useDark } from '@vueuse/core';
import IntlTelInput from '@intl-tel-input/vue';
import * as countryTimezones from 'countries-and-timezones';
import 'intl-tel-input/styles';

import useAuthStore from '@/stores/auth';
import { register as apiRegister } from '@/api';
import { useFormErrors } from '@/composables/useFormErrors';
import InputComponent from '@/components/common/Input.vue';
import SpinnerIcon from '@/assets/svgs/Spinner.svg';
import SunIcon from '@/assets/svgs/Sun.svg';
import MoonIcon from '@/assets/svgs/Moon.svg';

const router = useRouter();
const auth = useAuthStore();

const isDark = useDark();
const {
    errors,
    formError,
    setFromError,
    clear,
} = useFormErrors(['name', 'username', 'email', 'password', 'phone']);

const name = ref('');
const username = ref('');
const email = ref('');
const password = ref('');
const confirmPassword = ref('');
const confirmPasswordError = ref('');
const phoneError = ref('');
const phoneInput = ref('');
const phoneE164 = ref('');
const isPhoneValid = ref<boolean | null>(null);

const isSending = ref(false);

// Leave empty to show no preselected country when detection fails.
// You can set this to your application's primary market, e.g. 'nl'.
const FALLBACK_PHONE_COUNTRY = '';

function detectInitialPhoneCountry(): string {
    if (typeof window === 'undefined') {
        return FALLBACK_PHONE_COUNTRY;
    }

    // 1. Prefer the browser's configured timezone.
    try {
        const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (timeZone) {
            const country = countryTimezones.getCountryForTimezone(timeZone);

            if (country?.id) {
                return country.id.toLowerCase();
            }
        }
    } catch {
        // Continue with locale-based detection.
    }

    // 2. Fall back to the user's preferred browser locales.
    const locales = [
        ...(navigator.languages ?? []),
        navigator.language,
    ];

    for (const locale of new Set(locales)) {
        try {
            const region = new Intl.Locale(locale).region;

            if (region && countryTimezones.getCountry(region.toUpperCase())) {
                return region.toLowerCase();
            }
        } catch {
            // Ignore unsupported or malformed locale identifiers.
        }
    }

    // 3. Use the configured fallback, or leave the country unselected.
    return FALLBACK_PHONE_COUNTRY;
}

// Resolve the country before the phone component is initialised.
const initialPhoneCountry = detectInitialPhoneCountry();

// The lib's stylesheet is unlayered, so it beats Tailwind utilities unless
// they are marked important (trailing `!` in Tailwind v4).
const phoneInputClass = [
    'min-h-[42px] w-full text-base sm:text-sm',
    'rounded-lg! border! border-gray-300! bg-white! text-gray-900!',
    'placeholder:text-gray-400',
    'focus:outline-none focus:border-blue-500! focus:ring-2 focus:ring-blue-500/25',
    'dark:border-gray-600! dark:bg-gray-800! dark:text-gray-100!',
].join(' ');

// Load formatting and validation utilities on demand.
const loadPhoneUtils = () => import('intl-tel-input/utils');

function handlePhoneNumberChange(number: string): void {
    // The library emits the normalised international E.164 number.
    phoneE164.value = number;

    if (!number && !phoneInput.value.trim()) {
        phoneError.value = '';
        isPhoneValid.value = null;
    }
}

function handlePhoneValidityChange(valid: boolean): void {
    isPhoneValid.value = valid;

    if (valid) {
        phoneError.value = '';
    }
}

async function register(): Promise<void> {
    if (isSending.value) return;

    clear();
    confirmPasswordError.value = '';
    phoneError.value = '';

    // Phone is optional, but a supplied number must be valid.
    if (
        phoneInput.value.trim() &&
        (isPhoneValid.value !== true || !phoneE164.value)
    ) {
        phoneError.value =
            'Enter a valid phone number, or leave this field blank.';
        return;
    }

    isSending.value = true;

    try {
        const res = await apiRegister({
            name: name.value,
            username: username.value,
            email: email.value,
            password: password.value,
            password_confirmation: confirmPassword.value,

            // Send null when the optional field is empty.
            phone: phoneE164.value || null,
        });

        auth.setUser(res);
        await router.push({ path: '/' });
    } catch (err: unknown) {
        setFromError(err);

        const axiosErr = err as {
            response?: {
                data?: {
                    errors?: Record<string, string[]>;
                };
            };
        };

        const serverErrors = axiosErr.response?.data?.errors;

        if (serverErrors?.password_confirmation?.[0]) {
            confirmPasswordError.value = serverErrors.password_confirmation[0];
        }
    } finally {
        isSending.value = false;
    }
}
</script>

<template>
    <!--
        Outer wrapper scrolls; the card uses `my-auto` so it is vertically
        centred when there is room and scrolls from the top (never clipped)
        when the viewport is short, e.g. landscape phones or open keyboards.
    -->
    <div class="relative flex flex-col h-full min-h-dvh scrollable
                bg-gray-50 dark:bg-gray-900
                px-4 pt-16 pb-6 sm:px-6 sm:py-10
                pl-[max(1rem,env(safe-area-inset-left))]
                pr-[max(1rem,env(safe-area-inset-right))]
                pb-[max(1.5rem,env(safe-area-inset-bottom))]">

        <!-- Theme toggle -->
        <button type="button" role="switch" :aria-checked="isDark"
            :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'" class="absolute top-4 right-4 sm:top-6 sm:right-6 z-10 inline-flex items-center justify-center
                   size-10 rounded-full border border-gray-200 bg-white text-gray-600 shadow-sm
                   transition-colors hover:bg-gray-100 hover:text-gray-900
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500
                   focus-visible:ring-offset-2 focus-visible:ring-offset-gray-50
                   dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300
                   dark:hover:bg-gray-700 dark:hover:text-white
                   dark:focus-visible:ring-offset-gray-900" @click="isDark = !isDark">
            <SunIcon v-if="isDark" class="size-6 shrink-0 stroke-gray-100" />
            <MoonIcon v-else class="size-6 shrink-0 stroke-gray-500" />
        </button>

        <div class="w-full max-w-md sm:max-w-lg mx-auto my-auto">
            <div class="bg-white p-5 sm:p-8 shadow-sm border border-gray-100
                        dark:border-gray-700/60 dark:bg-gray-800 rounded-2xl">
                <h2 class="text-xl sm:text-2xl font-bold text-center text-gray-900 dark:text-gray-100">
                    Create an account
                </h2>

                <p class="mt-1 text-sm text-center text-gray-500 dark:text-gray-400">
                    Enter your details to sign up
                </p>

                <form class="mt-5 sm:mt-6 space-y-4" novalidate @submit.prevent="register">
                    <InputComponent v-model="name" :error-str="errors.name" name="name" label="Full Name"
                        placeholder="John Doe" autocomplete="name" />

                    <InputComponent v-model="username" :error-str="errors.username" name="username" label="Username"
                        placeholder="johndoe" autocomplete="username" />

                    <InputComponent v-model="email" :error-str="errors.email" name="email" type="email"
                        label="Email Address" placeholder="john@example.com" autocomplete="email" />

                    <InputComponent v-model="password" :error-str="errors.password" type="password" name="password"
                        label="Password" placeholder="••••••••" autocomplete="new-password" />

                    <InputComponent v-model="confirmPassword" :error-str="confirmPasswordError" type="password"
                        name="confirm_password" label="Confirm Password" placeholder="••••••••"
                        autocomplete="new-password" />

                    <!-- Optional international phone number -->
                    <div class="space-y-1.5">
                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Phone Number
                            <span class="font-normal text-gray-500 dark:text-gray-400">(optional)</span>
                        </label>

                        <IntlTelInput v-model="phoneInput" :initial-country="initialPhoneCountry"
                            :separate-dial-code="true" :load-utils="loadPhoneUtils" :input-props="{
                                id: 'phone',
                                name: 'phone',
                                type: 'tel',
                                inputmode: 'tel',
                                autocomplete: 'tel',
                                placeholder: 'Enter phone number',
                                'aria-label': 'Phone number (optional)',
                                class: phoneInputClass
                            }" @change-number="handlePhoneNumberChange" @change-validity="handlePhoneValidityChange" />

                        <p v-if="phoneError || errors.phone" class="text-xs font-medium text-red-500 dark:text-red-400"
                            role="alert">
                            {{ phoneError || errors.phone }}
                        </p>

                        <p v-else class="text-xs text-gray-500 dark:text-gray-400">
                            Include your country code if entering an international number.
                        </p>
                    </div>

                    <p v-show="formError" class="p-1 text-xs font-medium text-red-500 dark:text-red-400" role="alert">
                        {{ formError }}
                    </p>

                    <div class="pt-2">
                        <button :disabled="isSending" type="submit"
                            :class="{ 'opacity-70 cursor-not-allowed': isSending }"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 sm:py-2.5 text-sm font-medium tracking-wide text-white bg-blue-600 rounded-lg transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 active:scale-[0.99]">
                            <SpinnerIcon v-if="isSending" />
                            <span v-if="isSending">Creating account...</span>
                            <span v-else>Sign up</span>
                        </button>
                    </div>

                    <p class="text-sm text-center text-gray-600 dark:text-gray-400 pt-2">
                        Already have an account?
                        <RouterLink to="sign-in"
                            class="ml-1 font-medium text-blue-600 hover:underline dark:text-blue-400 whitespace-nowrap">
                            Log in
                        </RouterLink>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>

<!--
    Unscoped on purpose: the lib wraps the <input> in `.iti` at runtime and, on
    small screens, moves the country dropdown to <body>, so scoped styles
    can't reach either. Theming uses the lib's documented CSS variables.
-->
<style>
.iti {
    width: 100%;
}

.dark .iti,
.dark .iti--container {
    --iti-border-color: #4b5563;
    --iti-hover-color: #374151;
    --iti-icon-color: #d1d5db;
    --iti-arrow-color: #d1d5db;
    --iti-dialcode-color: #9ca3af;
    --iti-country-selector-bg: #1f2937;
    --iti-dropdown-bg: #1f2937;
    color: #f9fafb;
    color-scheme: dark;
}

.dark .iti__search-input {
    background-color: #111827;
    color: #f9fafb;
}
</style>