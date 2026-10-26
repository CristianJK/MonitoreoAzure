import { ref } from 'vue';
import { userService, type CurrentUser } from '@/services/userService';
import { agentService } from '@/services/agentService';

// Estado compartido entre vistas: usuario dueño del PAT y propuestas pendientes del agente
const currentUser = ref<CurrentUser | null>(null);
const pendingApprovals = ref(0);
let userRequest: Promise<void> | null = null;

const loadCurrentUser = () => {
    userRequest ??= userService.me()
        .then((user) => { currentUser.value = user; })
        .catch(() => { userRequest = null; });
    return userRequest;
};

const refreshPendingApprovals = async () => {
    try {
        pendingApprovals.value = await agentService.pendingCount();
    } catch {
        // El contador es informativo; si falla no se interrumpe la vista
    }
};

export const useSession = () => ({
    currentUser,
    pendingApprovals,
    loadCurrentUser,
    refreshPendingApprovals,
});
