<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import { repositoryService, type PullRequest, type PullRequestSuggestion, type Repository } from '@/services/repositoryService';
import { agentService, type AgentApproval, type ClosurePullRequestRef } from '@/services/agentService';
import type { LinkedPullRequest } from '@/services/workItemService';
import { errorMessage } from '@/services/apiClient';
import { formatDate } from '@/composables/useWorkItemStyles';

/**
 * Comentario de cierre de un WI finalizado: se eligen el repositorio y los PRs
 * (completados) y se genera una propuesta por PR. Los PRs que no estén
 * enlazados al WI se enlazan al generar. Nada se publica sin revisión.
 */
const props = defineProps<{
    open: boolean;
    workItemId: number;
    linkedPullRequests: LinkedPullRequest[];
}>();

const emit = defineEmits<{
    close: [];
    created: [approvals: AgentApproval[]];
}>();

interface Option {
    key: string;
    repositoryId: string;
    pullRequestId: number;
    title: string;
    repository: string;
    sourceBranch: string;
    targetBranch: string;
    closedDate: string | null;
    note?: string;
}

const repositories = ref<Repository[]>([]);
const suggestions = ref<PullRequestSuggestion[]>([]);
const repoPullRequests = ref<PullRequest[]>([]);
const selectedRepo = ref('');
const search = ref('');
const selected = ref<Record<string, Option>>({});
const loadingRepos = ref(false);
const loadingSuggestions = ref(false);
const loadingPullRequests = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);

const keyOf = (repositoryId: string, pullRequestId: number) => `${repositoryId}:${pullRequestId}`;

const fromListItem = (pr: PullRequest, note?: string): Option => ({
    key: keyOf(pr.repositoryId, pr.pullRequestId),
    repositoryId: pr.repositoryId,
    pullRequestId: pr.pullRequestId,
    title: pr.title,
    repository: pr.repository,
    sourceBranch: pr.sourceRefName,
    targetBranch: pr.targetRefName,
    closedDate: pr.closedDate,
    note,
});

// PRs ya enlazados: solo los completados sirven para el cierre
const linkedOptions = computed<Option[]>(() => props.linkedPullRequests
    .filter((pr) => !pr.error && pr.status === 'completed')
    .map((pr) => ({
        key: keyOf(pr.repositoryId, pr.id),
        repositoryId: pr.repositoryId,
        pullRequestId: pr.id,
        title: pr.title ?? '',
        repository: pr.repository ?? '',
        sourceBranch: pr.sourceBranch ?? '',
        targetBranch: pr.targetBranch ?? '',
        closedDate: pr.closedDate ?? null,
        note: 'Enlazado',
    })));

const notCompletedLinked = computed(() => props.linkedPullRequests.filter((pr) => !pr.error && pr.status !== 'completed' && pr.status !== 'abandoned'));

const suggestionOptions = computed<Option[]>(() => suggestions.value
    .filter((pr) => pr.status === 'completed')
    .map((pr) => fromListItem(pr, pr.matchReason)));

const repoOptions = computed<Option[]>(() => {
    const shown = new Set([...linkedOptions.value, ...suggestionOptions.value].map((o) => o.key));
    return repoPullRequests.value.map((pr) => fromListItem(pr)).filter((o) => !shown.has(o.key));
});

const selectedList = computed(() => Object.values(selected.value));

const toggle = (option: Option) => {
    if (selected.value[option.key]) {
        const { [option.key]: _removed, ...rest } = selected.value;
        selected.value = rest;
    } else {
        selected.value = { ...selected.value, [option.key]: option };
    }
};

const loadInitial = async () => {
    error.value = null;
    selected.value = {};
    selectedRepo.value = '';
    search.value = '';
    repoPullRequests.value = [];

    loadingRepos.value = true;
    loadingSuggestions.value = true;
    repositoryService.getRepositories()
        .then((repos) => { repositories.value = repos; })
        .catch((e) => { error.value = errorMessage(e, 'Error al cargar los repositorios.'); })
        .finally(() => { loadingRepos.value = false; });
    repositoryService.getPullRequestSuggestions(props.workItemId)
        .then((items) => { suggestions.value = items; })
        .catch(() => { suggestions.value = []; })
        .finally(() => { loadingSuggestions.value = false; });

    // Si solo hay un PR completado enlazado, se preselecciona
    if (linkedOptions.value.length === 1) toggle(linkedOptions.value[0]);
};

watch(() => props.open, (open) => { if (open) loadInitial(); });

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const loadPullRequests = async () => {
    if (!selectedRepo.value) {
        repoPullRequests.value = [];
        return;
    }
    loadingPullRequests.value = true;
    try {
        repoPullRequests.value = await repositoryService.getPullRequests(selectedRepo.value, 'completed', search.value.trim());
    } catch (e) {
        error.value = errorMessage(e, 'Error al cargar los Pull Requests del repositorio.');
    } finally {
        loadingPullRequests.value = false;
    }
};

watch(selectedRepo, loadPullRequests);
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadPullRequests, 350);
});

const submit = async () => {
    if (!selectedList.value.length) {
        error.value = 'Selecciona el repositorio y al menos un Pull Request completado.';
        return;
    }
    submitting.value = true;
    error.value = null;
    try {
        const refs: ClosurePullRequestRef[] = selectedList.value.map((o) => ({ repositoryId: o.repositoryId, pullRequestId: o.pullRequestId }));
        const approvals = await agentService.proposeClosure(props.workItemId, refs);
        emit('created', approvals);
        emit('close');
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo generar el comentario de cierre');
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <AppModal
        :open="open"
        title="Comentario de cierre"
        :subtitle="`Work Item #${workItemId} · se genera un comentario por cada PR seleccionado`"
        size="lg"
        :closable="!submitting"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <div v-if="error" class="p-3 bg-error/10 border border-error/30 text-error text-body-sm rounded-lg" role="alert">{{ error }}</div>

            <div v-if="!linkedPullRequests.length" class="flex items-start gap-2.5 p-3 rounded-lg bg-warning/10 border border-warning/30 text-body-sm text-on-surface">
                <span class="material-symbols-outlined text-warning text-[20px] shrink-0" aria-hidden="true">info</span>
                <p>Este work item no tiene Pull Request asociado. Selecciona el repositorio y el PR con el que se realizó; se enlazará al work item al generar el comentario.</p>
            </div>
            <p v-for="pr in notCompletedLinked" :key="pr.id" class="text-[12px] text-warning">
                El PR !{{ pr.id }} está {{ pr.status === 'active' ? 'activo' : pr.status }}: el cierre solo se genera para PRs completados.
            </p>

            <!-- Enlazados y sugeridos -->
            <fieldset v-if="linkedOptions.length || suggestionOptions.length || loadingSuggestions" class="space-y-1.5">
                <legend class="field-label">PRs enlazados y sugeridos</legend>
                <p v-if="loadingSuggestions && !linkedOptions.length" class="text-[12px] text-on-surface-variant">Buscando PRs relacionados...</p>
                <label
                    v-for="option in [...linkedOptions, ...suggestionOptions]"
                    :key="option.key"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition-colors"
                    :class="selected[option.key] ? 'border-primary bg-primary/10' : 'border-outline-variant bg-surface-container hover:bg-surface-container-high'"
                >
                    <input type="checkbox" class="mt-1 accent-primary" :checked="!!selected[option.key]" @change="toggle(option)" />
                    <span class="min-w-0 text-body-sm">
                        <span class="block font-semibold text-on-surface break-words">PR !{{ option.pullRequestId }} · {{ option.title }}</span>
                        <span class="block text-[11px] text-on-surface-variant font-label-mono truncate">{{ option.repository }} · {{ option.sourceBranch }} → {{ option.targetBranch }}</span>
                        <span class="block text-[11px] text-on-surface-variant">
                            <template v-if="option.closedDate">Completado {{ formatDate(option.closedDate, false) }} · </template>{{ option.note }}
                        </span>
                    </span>
                </label>
            </fieldset>

            <!-- Búsqueda por repositorio -->
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="closure-repo" class="field-label">Repositorio</label>
                    <select id="closure-repo" v-model="selectedRepo" class="field" :disabled="loadingRepos">
                        <option value="">{{ loadingRepos ? 'Cargando repositorios...' : 'Selecciona un repositorio...' }}</option>
                        <option v-for="repo in repositories" :key="repo.id" :value="repo.id">{{ repo.name }}</option>
                    </select>
                </div>
                <div>
                    <label for="closure-search" class="field-label">Buscar PR completado</label>
                    <input id="closure-search" v-model="search" type="search" class="field" placeholder="Título, rama o número" :disabled="!selectedRepo" />
                </div>
            </div>

            <div v-if="selectedRepo" class="max-h-64 overflow-y-auto custom-scrollbar space-y-1.5">
                <p v-if="loadingPullRequests" class="text-[12px] text-primary">Cargando Pull Requests completados...</p>
                <p v-else-if="!repoOptions.length" class="text-[12px] text-on-surface-variant italic">No hay Pull Requests completados que coincidan.</p>
                <label
                    v-for="option in repoOptions"
                    :key="option.key"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg border cursor-pointer transition-colors"
                    :class="selected[option.key] ? 'border-primary bg-primary/10' : 'border-outline-variant bg-surface-container hover:bg-surface-container-high'"
                >
                    <input type="checkbox" class="mt-1 accent-primary" :checked="!!selected[option.key]" @change="toggle(option)" />
                    <span class="min-w-0 text-body-sm">
                        <span class="block font-semibold text-on-surface break-words">PR !{{ option.pullRequestId }} · {{ option.title }}</span>
                        <span class="block text-[11px] text-on-surface-variant font-label-mono truncate">{{ option.sourceBranch }} → {{ option.targetBranch }}</span>
                        <span v-if="option.closedDate" class="block text-[11px] text-on-surface-variant">Completado {{ formatDate(option.closedDate, false) }}</span>
                    </span>
                </label>
            </div>
        </div>

        <template #footer>
            <span class="text-[12px] text-on-surface-variant sm:mr-auto self-center">{{ selectedList.length }} PR(s) seleccionado(s)</span>
            <button type="button" class="btn btn-ghost" :disabled="submitting" @click="emit('close')">Cancelar</button>
            <button type="button" class="btn btn-primary" :disabled="submitting || !selectedList.length" @click="submit">
                <span class="material-symbols-outlined text-[16px]" :class="{ 'animate-spin-slow': submitting }" aria-hidden="true">{{ submitting ? 'auto_awesome' : 'task_alt' }}</span>
                {{ submitting ? 'Generando resumen...' : 'Generar comentario(s)' }}
            </button>
        </template>
    </AppModal>
</template>
