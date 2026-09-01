import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import type { User } from '@/types';
import type { AuthResponse } from '@/types/api';

const STORAGE_KEY = 'currentUser';

function loadStoredUser(): User | null {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw) as User;
    } catch {
        return null;
    }
}

export default defineStore('auth', () => {
    const user = ref<User | null>(loadStoredUser());

    // Persist the session so a page refresh keeps the user logged in.
    watch(user, (next) => {
        if (next == null) {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        }
    });

    const isAuthenticated = computed(() => user.value != null);

    /** Store the user returned by a login / register / oauth response. */
    function setUser(response: AuthResponse): void {
        user.value = {
            token: response.token,
            id: response.data.id,
            name: response.data.name,
            username: response.data.username,
            email: response.data.email,
        };
    }

    function logout(): void {
        user.value = null;
    }

    return { user, isAuthenticated, setUser, logout };
});
