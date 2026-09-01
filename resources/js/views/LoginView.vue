<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import { login as loginUserApi, oauthRedirect } from '@/api';
import { useFormErrors } from '@/composables/useFormErrors';
import InputComponent from '@/components/common/Input.vue';
import GithubIcon from '@/assets/svgs/Github.svg';
import GoogleIcon from '@/assets/svgs/Google.svg';
import FacebookIcon from '@/assets/svgs/Facebook.svg';
import SlackIcon from '@/assets/svgs/Slack.svg';

const router = useRouter();
const auth = useAuthStore();
const { errors, formError, setFromError, clear } = useFormErrors(['username', 'password']);

const username = ref('');
const password = ref('');
const isSending = ref(false);
const showPass = ref(false);

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
    <div class="flex flex-col items-center justify-center h-full overflow-auto min-h-fit">
        <div class="w-full sm:max-w-md">
            <div class="p-4 bg-white sm:p-8 sm:shadow-equal dark:bg-gray-800 rounded-2xl">
                <h2 class="text-2xl font-bold text-center">Sign in</h2>
                <form class="mt-8 space-y-4" @submit.prevent="loginUser">
                    <InputComponent v-model="username" :error-str="errors.username" name="username" label="Username" />

                    <InputComponent
                        v-model="password"
                        :error-str="errors.password"
                        name="password"
                        label="Password"
                        :type="showPass ? 'text' : 'password'"
                    >
                        <template #icon>
                            <svg
                                v-show="showPass"
                                class="absolute w-4 h-4 cursor-pointer right-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                @click="showPass = false"
                            >
                                <path
                                    fill-rule="evenodd"
                                    clip-rule="evenodd"
                                    d="M11.9944 15.5C13.9274 15.5 15.4944 13.933 15.4944 12C15.4944 10.067 13.9274 8.5 11.9944 8.5C10.0614 8.5 8.49439 10.067 8.49439 12C8.49439 13.933 10.0614 15.5 11.9944 15.5ZM11.9944 13.4944C11.1691 13.4944 10.5 12.8253 10.5 12C10.5 11.1747 11.1691 10.5056 11.9944 10.5056C12.8197 10.5056 13.4888 11.1747 13.4888 12C13.4888 12.8253 12.8197 13.4944 11.9944 13.4944Z"
                                    fill="#bbb"
                                />
                                <path
                                    fill-rule="evenodd"
                                    clip-rule="evenodd"
                                    d="M12 5C7.18879 5 3.9167 7.60905 2.1893 9.47978C0.857392 10.9222 0.857393 13.0778 2.1893 14.5202C3.9167 16.391 7.18879 19 12 19C16.8112 19 20.0833 16.391 21.8107 14.5202C23.1426 13.0778 23.1426 10.9222 21.8107 9.47978C20.0833 7.60905 16.8112 5 12 5ZM3.65868 10.8366C5.18832 9.18002 7.9669 7 12 7C16.0331 7 18.8117 9.18002 20.3413 10.8366C20.9657 11.5128 20.9657 12.4872 20.3413 13.1634C18.8117 14.82 16.0331 17 12 17C7.9669 17 5.18832 14.82 3.65868 13.1634C3.03426 12.4872 3.03426 11.5128 3.65868 10.8366Z"
                                    fill="#bbb"
                                />
                            </svg>
                            <svg
                                v-show="!showPass"
                                class="absolute w-4 h-4 cursor-pointer right-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                @click="showPass = true"
                            >
                                <path
                                    d="M4.4955 7.44088C3.54724 8.11787 2.77843 8.84176 2.1893 9.47978C0.857392 10.9222 0.857393 13.0778 2.1893 14.5202C3.9167 16.391 7.18879 19 12 19C13.2958 19 14.4799 18.8108 15.5523 18.4977L13.8895 16.8349C13.2936 16.9409 12.6638 17 12 17C7.9669 17 5.18832 14.82 3.65868 13.1634C3.03426 12.4872 3.03426 11.5128 3.65868 10.8366C4.23754 10.2097 4.99526 9.50784 5.93214 8.87753L4.4955 7.44088Z"
                                    fill="#bbb"
                                />
                                <path
                                    d="M8.53299 11.4784C8.50756 11.6486 8.49439 11.8227 8.49439 12C8.49439 13.933 10.0614 15.5 11.9944 15.5C12.1716 15.5 12.3458 15.4868 12.516 15.4614L8.53299 11.4784Z"
                                    fill="#bbb"
                                />
                                <path
                                    d="M15.4661 12.4471L11.5473 8.52829C11.6937 8.50962 11.8429 8.5 11.9944 8.5C13.9274 8.5 15.4944 10.067 15.4944 12C15.4944 12.1515 15.4848 12.3007 15.4661 12.4471Z"
                                    fill="#bbb"
                                />
                                <path
                                    d="M18.1118 15.0928C19.0284 14.4702 19.7715 13.7805 20.3413 13.1634C20.9657 12.4872 20.9657 11.5128 20.3413 10.8366C18.8117 9.18002 16.0331 7 12 7C11.3594 7 10.7505 7.05499 10.1732 7.15415L8.50483 5.48582C9.5621 5.1826 10.7272 5 12 5C16.8112 5 20.0833 7.60905 21.8107 9.47978C23.1426 10.9222 23.1426 13.0778 21.8107 14.5202C21.2305 15.1486 20.476 15.8603 19.5474 16.5284L18.1118 15.0928Z"
                                    fill="#bbb"
                                />
                                <path
                                    d="M2.00789 3.42207C1.61736 3.03155 1.61736 2.39838 2.00789 2.00786C2.39841 1.61733 3.03158 1.61733 3.4221 2.00786L22.0004 20.5862C22.391 20.9767 22.391 21.6099 22.0004 22.0004C21.6099 22.3909 20.9767 22.3909 20.5862 22.0004L2.00789 3.42207Z"
                                    fill="#bbb"
                                />
                            </svg>
                        </template>
                    </InputComponent>

                    <p v-show="formError" class="px-1 text-sm text-red-600">{{ formError }}</p>

                    <div class="!mt-8">
                        <button
                            :disabled="isSending"
                            type="submit"
                            :class="{ 'cursor-not-allowed': isSending, 'animate-pulse': isSending }"
                            class="w-full px-4 py-3 text-sm tracking-wide text-white transition-colors bg-blue-600 border-2 border-blue-600 rounded-lg outline-none cursor-pointer hover:bg-blue-700 focus:border-blue-300"
                        >
                            Sign in
                        </button>
                    </div>
                    <p class="text-sm !mt-8 text-center">
                        Don't have an account?
                        <RouterLink
                            to="sign-up"
                            class="ml-1 font-semibold text-blue-600 hover:underline whitespace-nowrap"
                        >
                            Register here
                        </RouterLink>
                    </p>
                </form>

                <!-- OAuth Separator -->
                <div class="relative my-6 w-full max-w-[95%] mx-auto">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-300 dark:border-gray-700"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-1 text-gray-500 bg-white dark:bg-gray-800 dark:text-gray-400">
                            or sign in with
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <div class="flex-[1_1_45%]">
                        <button
                            class="flex items-center w-full gap-3 px-4 py-2 pl-12 text-black bg-white border border-gray-300 rounded sm:pl-8 dark:bg-gray-900 dark:text-white dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800"
                            @click="oauthLogin('github')"
                        >
                            <GithubIcon class="w-5 h-5 fill-current" />
                            GitHub
                        </button>
                    </div>
                    <div class="flex-[1_1_45%]">
                        <button
                            class="flex items-center w-full gap-3 px-4 py-2 pl-12 text-black bg-white border border-gray-300 rounded sm:pl-8 dark:bg-gray-900 dark:text-white dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800"
                            @click="oauthLogin('google')"
                        >
                            <GoogleIcon class="w-5 h-5" />
                            Google
                        </button>
                    </div>
                    <div class="flex-[1_1_45%]">
                        <button
                            class="flex items-center w-full gap-3 px-4 py-2 pl-12 text-black bg-white border border-gray-300 rounded sm:pl-8 dark:bg-gray-900 dark:text-white dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800"
                            @click="oauthLogin('facebook')"
                        >
                            <FacebookIcon class="w-5 h-5" />
                            Facebook
                        </button>
                    </div>
                    <div class="flex-[1_1_45%]">
                        <button
                            class="flex items-center w-full gap-3 px-4 py-2 pl-12 text-black bg-white border border-gray-300 rounded sm:pl-8 dark:bg-gray-900 dark:text-white dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800"
                            @click="oauthLogin('slack')"
                        >
                            <SlackIcon class="w-5 h-5" />
                            Slack
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
