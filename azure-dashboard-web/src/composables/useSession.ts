import { computed, ref } from 'vue';
import { authService, userService, type CurrentUser } from '@/services/userService';
import { agentService } from '@/services/agentService';

// Estado compartido entre vistas: usuario de la sesión y sus propuestas pendientes del agente
const currentUser = ref<CurrentUser | null>(null);
const pendingApprovals = ref(0);
let userRequest: Promise<CurrentUser | null> | null = null;

/** Devuelve el usuario de la sesión o null si no hay sesión (nunca lanza). */
const loadCurrentUser = () => {
    userRequest ??= userService.me()
        .then((user) => (currentUser.value = user))
        .catch(() => {
            userRequest = null;
            return null;
        });
    return userRequest;
};

const login = async (pat: string) => {
    const user = await authService.login(pat);
    currentUser.value = user;
    userRequest = Promise.resolve(user);
    return user;
};

const clearSession = () => {
    currentUser.value = null;
    pendingApprovals.value = 0;
    userRequest = null;
};

const logout = async () => {
    try {
        await authService.logout();
    } finally {
        clearSession();
    }
};

const refreshPendingApprovals = async () => {
    try {
        pendingApprovals.value = await agentService.pendingCount();
    } catch {
        // El contador es informativo; si falla no se interrumpe la vista
    }
};

const isAdmin = computed(() => !!currentUser.value?.isAdmin);

export const useSession = () => ({
    currentUser,
    pendingApprovals,
    isAdmin,
    loadCurrentUser,
    login,
    clearSession,
    logout,
    refreshPendingApprovals,
});
