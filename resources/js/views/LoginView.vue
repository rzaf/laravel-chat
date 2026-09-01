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
import EyeVisibleIcon from '@/assets/svgs/EyeVisible.svg';
import EyeHiddenIcon from '@/assets/svgs/EyeHidden.svg';

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
                            <EyeVisibleIcon
                                v-show="showPass"
                                class="absolute w-4 h-4 cursor-pointer right-4"
                                @click="showPass = false"
                            />
                            <EyeHiddenIcon
                                v-show="!showPass"
                                class="absolute w-4 h-4 cursor-pointer right-4"
                                @click="showPass = true"
                            />
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
