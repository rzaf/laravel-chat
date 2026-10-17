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
import SpinnerIcon from '@/assets/svgs/Spinner.svg';
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
    <div class="flex flex-col items-center justify-center h-full px-4 py-6 overflow-auto min-h-fit">
        <div class="w-full sm:max-w-md">
            <div
                class="p-6 bg-white sm:p-8 shadow-sm border border-gray-100 dark:border-gray-700/60 dark:bg-gray-800 rounded-2xl">
                <h2 class="text-2xl font-bold text-center text-gray-900 dark:text-gray-100">Welcome back</h2>
                <p class="mt-1 text-sm text-center text-gray-500 dark:text-gray-400">Please sign in to your account</p>

                <form class="mt-6 space-y-4" @submit.prevent="loginUser">
                    <InputComponent v-model="username" :error-str="errors.username" name="username"
                        label="Username or Email" placeholder="johndoe or john@example.com" autocomplete="username" />

                    <div>
                        <InputComponent v-model="password" :error-str="errors.password" name="password" label="Password"
                            placeholder="••••••••" :type="showPass ? 'text' : 'password'"
                            autocomplete="current-password">
                            <template #icon>
                                <button type="button"
                                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 focus:outline-none"
                                    @click="showPass = !showPass">
                                    <EyeVisibleIcon v-if="showPass" class="w-5 h-5" />
                                    <EyeHiddenIcon v-else class="w-5 h-5" />
                                </button>
                            </template>
                        </InputComponent>

                        <!-- Optional: Forgot Password Link -->
                        <div class="flex justify-end mt-1">
                            <RouterLink to="forgot-password"
                                class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                                Forgot password?
                            </RouterLink>
                        </div>
                    </div>

                    <p v-show="formError" class="p-1 text-xs font-medium text-red-500 dark:text-red-400">{{ formError }}
                    </p>

                    <div class="pt-2">
                        <button :disabled="isSending" type="submit"
                            :class="{ 'opacity-70 cursor-not-allowed': isSending }"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium tracking-wide text-white bg-blue-600 rounded-lg transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 active:scale-[0.99]">

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
                <div class="relative my-6 w-full">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200 dark:border-gray-700"></div>
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="px-2 text-gray-400 bg-white dark:bg-gray-800">
                            Or continue with
                        </span>
                    </div>
                </div>

                <!-- OAuth Buttons (Clean Grid Layout) -->
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button"
                        class="flex items-center justify-center gap-2.5 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 hover:bg-gray-100/70 dark:hover:bg-gray-800/80 transition-colors"
                        @click="oauthLogin('github')">
                        <GithubIcon class="w-4 h-4 fill-current" />
                        GitHub
                    </button>
                    <button type="button"
                        class="flex items-center justify-center gap-2.5 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 hover:bg-gray-100/70 dark:hover:bg-gray-800/80 transition-colors"
                        @click="oauthLogin('google')">
                        <GoogleIcon class="w-4 h-4" />
                        Google
                    </button>
                    <button type="button"
                        class="flex items-center justify-center gap-2.5 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 hover:bg-gray-100/70 dark:hover:bg-gray-800/80 transition-colors"
                        @click="oauthLogin('facebook')">
                        <FacebookIcon class="w-4 h-4" />
                        Facebook
                    </button>
                    <button type="button"
                        class="flex items-center justify-center gap-2.5 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 hover:bg-gray-100/70 dark:hover:bg-gray-800/80 transition-colors"
                        @click="oauthLogin('slack')">
                        <SlackIcon class="w-4 h-4" />
                        Slack
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>