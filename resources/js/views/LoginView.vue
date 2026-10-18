<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useDark } from '@vueuse/core';
import useAuthStore from '@/stores/auth';
import { login as loginUserApi, oauthRedirect } from '@/api';
import { useFormErrors } from '@/composables/useFormErrors';
import InputComponent from '@/components/common/Input.vue';
import GithubIcon from '@/assets/svgs/Github.svg';
import GoogleIcon from '@/assets/svgs/Google.svg';
import FacebookIcon from '@/assets/svgs/Facebook.svg';
import SlackIcon from '@/assets/svgs/Slack.svg';
import SpinnerIcon from '@/assets/svgs/Spinner.svg';
import EyeVisibleIcon from '@/assets/svgs/EyeVisible.svg';
import EyeHiddenIcon from '@/assets/svgs/EyeHidden.svg';
import SunIcon from '@/assets/svgs/Sun.svg';
import MoonIcon from '@/assets/svgs/Moon.svg';

const router = useRouter();
const auth = useAuthStore();
const { errors, formError, setFromError, clear } = useFormErrors(['username', 'password']);

// Shared + persisted with the side menu toggle.
const isDark = useDark();

const username = ref('');
const password = ref('');
const isSending = ref(false);
const showPass = ref(false);

const providers = [
    { id: 'github', label: 'GitHub', icon: GithubIcon, iconClass: 'fill-current' },
    { id: 'google', label: 'Google', icon: GoogleIcon, iconClass: '' },
    { id: 'facebook', label: 'Facebook', icon: FacebookIcon, iconClass: '' },
    { id: 'slack', label: 'Slack', icon: SlackIcon, iconClass: '' },
];

async function loginUser(): Promise<void> {
    if (isSending.value) return;
    isSending.value = true;
    clear();

    try {
        const res = await loginUserApi({ login: username.value, password: password.value });
        auth.setUser(res);
        router.push('/');
    } catch (err: unknown) {
        setFromError(err);
    } finally {
        isSending.value = false;
    }
}

async function oauthLogin(provider: string): Promise<void> {
    try {
        const res = await oauthRedirect(provider);
        window.location.href = res.url;
    } catch {
        // Redirect fetch failed — stay on page so user can retry.
    }
}
</script>

<template>
    <!--
        Outer wrapper scrolls; the card uses `my-auto` so it is centred when
        there is room and scrolls from the top (never clipped) on short
        viewports such as landscape phones or with the keyboard open.
    -->
    <div class="relative flex flex-col h-full min-h-dvh scrollable
                bg-gray-50 dark:bg-gray-900
                px-4 pt-16 sm:px-6 sm:py-10
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
            <SunIcon v-if="isDark" class="size-5 shrink-0 stroke-gray-100" aria-hidden="true" />
            <MoonIcon v-else class="size-5 shrink-0 stroke-gray-500" aria-hidden="true" />
        </button>

        <div class="w-full max-w-md mx-auto my-auto">
            <div
                class="p-5 bg-white sm:p-8 shadow-sm border border-gray-100 dark:border-gray-700/60 dark:bg-gray-800 rounded-2xl">
                <h2 class="text-xl sm:text-2xl font-bold text-center text-gray-900 dark:text-gray-100">Welcome back</h2>
                <p class="mt-1 text-sm text-center text-gray-500 dark:text-gray-400">Please sign in to your account</p>

                <form class="mt-5 sm:mt-6 space-y-4" novalidate @submit.prevent="loginUser">
                    <InputComponent v-model="username" :error-str="errors.username" name="username"
                        label="Username or Email" placeholder="johndoe or john@example.com" autocomplete="username" />

                    <div>
                        <InputComponent v-model="password" :error-str="errors.password" name="password" label="Password"
                            placeholder="••••••••" :type="showPass ? 'text' : 'password'"
                            autocomplete="current-password">
                            <template #icon>
                                <button type="button" :aria-label="showPass ? 'Hide password' : 'Show password'"
                                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 focus:outline-none"
                                    @click="showPass = !showPass">
                                    <EyeVisibleIcon v-if="showPass" class="w-5 h-5" />
                                    <EyeHiddenIcon v-else class="w-5 h-5" />
                                </button>
                            </template>
                        </InputComponent>

                        <div class="flex justify-end mt-1">
                            <RouterLink to="forgot-password"
                                class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                                Forgot password?
                            </RouterLink>
                        </div>
                    </div>

                    <p v-show="formError" class="p-1 text-xs font-medium text-red-500 dark:text-red-400" role="alert">
                        {{ formError }}
                    </p>

                    <div class="pt-2">
                        <button :disabled="isSending" type="submit"
                            :class="{ 'opacity-70 cursor-not-allowed': isSending }"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 sm:py-2.5 text-sm font-medium tracking-wide text-white bg-blue-600 rounded-lg transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 active:scale-[0.99]">

                            <SpinnerIcon v-if="isSending" />
                            <span>{{ isSending ? 'Signing in...' : 'Sign in' }}</span>
                        </button>
                    </div>

                    <p class="text-sm text-center text-gray-600 dark:text-gray-400 pt-2">
                        Don't have an account?
                        <RouterLink to="sign-up"
                            class="ml-1 font-medium text-blue-600 hover:underline dark:text-blue-400 whitespace-nowrap">
                            Register here
                        </RouterLink>
                    </p>
                </form>

                <!-- OAuth Separator -->
                <div class="relative my-5 sm:my-6 w-full">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200 dark:border-gray-700"></div>
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="px-2 text-gray-400 bg-white dark:bg-gray-800">Or continue with</span>
                    </div>
                </div>

                <!-- OAuth buttons: single column on very narrow phones, two columns otherwise -->
                <div class="grid grid-cols-1 min-[380px]:grid-cols-2 gap-2.5">
                    <button v-for="p in providers" :key="p.id" type="button"
                        class="flex items-center justify-center gap-2.5 px-3 py-2.5 sm:py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 hover:bg-gray-100/70 dark:hover:bg-gray-800/80 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        @click="oauthLogin(p.id)">
                        <component :is="p.icon" class="w-4 h-4 shrink-0" :class="p.iconClass" />
                        {{ p.label }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>