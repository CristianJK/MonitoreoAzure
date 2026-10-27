<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorMessage } from '@/services/apiClient';
import { useSession } from '@/composables/useSession';

const route = useRoute();
const router = useRouter();
const { login } = useSession();

const pat = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const scopes = ['Work Items (Read & Write)', 'Code (Read)', 'Build (Read)', 'Agent Pools (Read)', 'Project and Team (Read)'];

const notices: Record<string, string> = {
    pat_invalido: 'Azure DevOps rechazó tu PAT (venció, fue revocado o le faltan permisos). Inicia sesión con uno nuevo.',
};
const notice = computed(() => notices[route.query.error as string] ?? null);

const submit = async () => {
    if (!pat.value.trim()) return;
    submitting.value = true;
    error.value = null;
    try {
        await login(pat.value.trim());
        pat.value = '';
        const redirect = route.query.redirect as string | undefined;
        router.replace(redirect?.startsWith('/') && !redirect.startsWith('//') ? redirect : '/');
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo iniciar sesión');
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <main class="min-h-screen flex items-center justify-center bg-background p-4">
        <form class="w-full max-w-md bg-surface-container border border-outline-variant rounded-xl p-6 flex flex-col gap-5" @submit.prevent="submit">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-primary-container rounded-lg flex items-center justify-center text-on-primary-container shrink-0">
                    <span class="material-symbols-outlined text-[22px]" aria-hidden="true">dashboard</span>
                </div>
                <div class="min-w-0">
                    <h1 class="font-headline-sm text-headline-sm text-on-surface">Monitoreo Azure</h1>
                    <p class="text-body-sm text-on-surface-variant">Inicia sesión con tu PAT de Azure DevOps</p>
                </div>
            </div>

            <p v-if="notice" class="flex items-start gap-2 text-body-sm text-warning" role="status">
                <span class="material-symbols-outlined text-[18px] shrink-0" aria-hidden="true">warning</span>
                {{ notice }}
            </p>

            <div>
                <label for="pat" class="field-label">Personal Access Token</label>
                <input
                    id="pat"
                    v-model="pat"
                    type="password"
                    class="field"
                    autocomplete="off"
                    spellcheck="false"
                    required
                    :disabled="submitting"
                    :aria-invalid="!!error"
                    aria-describedby="pat-help"
                />
                <p v-if="error" class="field-error" role="alert">{{ error }}</p>
            </div>

            <div id="pat-help" class="text-[12px] text-on-surface-variant space-y-1.5">
                <p>Créalo en Azure DevOps → <strong class="text-on-surface">User settings → Personal access tokens</strong>, con estos permisos:</p>
                <ul class="list-disc pl-5">
                    <li v-for="scope in scopes" :key="scope">{{ scope }}</li>
                </ul>
                <p>Se guarda cifrado en el servidor y se usa solo para tus acciones. Solo podrás modificar los work items asignados a ti.</p>
            </div>

            <button type="submit" class="btn btn-primary w-full justify-center" :disabled="submitting || !pat.trim()">
                <span class="material-symbols-outlined text-[18px]" :class="{ 'animate-spin': submitting }" aria-hidden="true">{{ submitting ? 'progress_activity' : 'login' }}</span>
                {{ submitting ? 'Validando en Azure DevOps...' : 'Iniciar sesión' }}
            </button>
        </form>
    </main>
</template>
