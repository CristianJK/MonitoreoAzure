<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import {
    repositoryService,
    type PullRequest,
    type PullRequestStatus,
    type PullRequestSuggestion,
    type Repository,
} from '../services/repositoryService';
import { errorMessage as apiErrorMessage } from '@/services/apiClient';
import { formatDate } from '@/composables/useWorkItemStyles';

const props = defineProps<{
    workItemId: number;
    isOpen: boolean;
}>();

const emit = defineEmits(['close', 'linked']);

const STATUS_OPTIONS: { value: PullRequestStatus; label: string }[] = [
    { value: 'active', label: 'Activos' },
    { value: 'completed', label: 'Completados' },
    { value: 'all', label: 'Todos' },
];

const STATUS_LABELS: Record<string, string> = { active: 'Activo', completed: 'Completado', abandoned: 'Abandonado' };

const repositories = ref<Repository[]>([]);
const pullRequests = ref<PullRequest[]>([]);
const suggestions = ref<PullRequestSuggestion[]>([]);
const selectedArtifactId = ref<string>('');
const selectedRepo = ref<string>('');
const status = ref<PullRequestStatus>('active');
const search = ref('');
const isLoadingRepos = ref<boolean>(false);
const isLoadingPullRequests = ref<boolean>(false);
const isLoadingSuggestions = ref<boolean>(false);
const isSubmitting = ref<boolean>(false);
const errorMessage = ref<string | null>(null);

onMounted(async () => {
    isLoadingSuggestions.value = true;
    repositoryService.getPullRequestSuggestions(props.workItemId)
        .then((items) => { suggestions.value = items; })
        .catch(() => { suggestions.value = []; })
        .finally(() => { isLoadingSuggestions.value = false; });

    try {
        isLoadingRepos.value = true;
        repositories.value = await repositoryService.getRepositories();
    } catch (e) {
        errorMessage.value = 'Error al cargar los repositorios.';
    } finally {
        isLoadingRepos.value = false;
    }
});

const loadPullRequests = async () => {
    selectedArtifactId.value = '';
    if (!selectedRepo.value) {
        pullRequests.value = [];
        return;
    }
    try {
        isLoadingPullRequests.value = true;
        pullRequests.value = await repositoryService.getPullRequests(selectedRepo.value, status.value, search.value.trim());
    } catch (e) {
        errorMessage.value = 'Error al cargar las solicitudes de extracción del repositorio.';
    } finally {
        isLoadingPullRequests.value = false;
    }
};

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch([selectedRepo, status], loadPullRequests);
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadPullRequests, 350);
});

const statusLabel = (value: string) => STATUS_LABELS[value] ?? value;

const prLabel = (pr: PullRequest) => {
    const closed = pr.closedDate ? ` · ${formatDate(pr.closedDate, false)}` : '';
    return `!${pr.pullRequestId} ${pr.title} (${pr.sourceRefName} → ${pr.targetRefName}) · ${statusLabel(pr.status)}${closed}`;
};

const link = async (artifactId: string) => {
    if (!artifactId) return;
    try {
        isSubmitting.value = true;
        errorMessage.value = null;
        await repositoryService.linkPullRequest(props.workItemId, artifactId);
        emit('linked');
        emit('close');
    } catch (e) {
        errorMessage.value = apiErrorMessage(e, 'Error al enlazar la solicitud de extracción.');
    } finally {
        isSubmitting.value = false;
    }
};

const canSubmit = computed(() => !!selectedRepo.value && !!selectedArtifactId.value && !isSubmitting.value);
</script>

<template>
    <AppModal
        :open="isOpen"
        :title="`Vincular PR a Work Item #${workItemId}`"
        size="lg"
        :closable="!isSubmitting"
        @close="$emit('close')"
    >
        <div class="space-y-4">
            <div v-if="errorMessage" class="p-3 bg-error/10 border border-error/30 text-error text-body-sm rounded-lg" role="alert">
                {{ errorMessage }}
            </div>

            <!-- Sugeridos -->
            <section v-if="isLoadingSuggestions || suggestions.length" class="space-y-1.5">
                <h4 class="field-label">PRs sugeridos</h4>
                <p v-if="isLoadingSuggestions" class="text-[12px] text-on-surface-variant">Buscando PRs relacionados con el work item...</p>
                <div
                    v-for="pr in suggestions"
                    :key="pr.artifactId"
                    class="flex items-start gap-2.5 p-2.5 rounded-lg border border-outline-variant bg-surface-container"
                >
                    <span class="material-symbols-outlined text-[18px] text-primary shrink-0" aria-hidden="true">merge</span>
                    <span class="flex-1 min-w-0 text-body-sm">
                        <span class="block font-semibold text-on-surface break-words">!{{ pr.pullRequestId }} · {{ pr.title }}</span>
                        <span class="block text-[11px] text-on-surface-variant font-label-mono truncate">{{ pr.repository }} · {{ pr.sourceRefName }} → {{ pr.targetRefName }}</span>
                        <span class="block text-[11px] text-on-surface-variant">
                            {{ statusLabel(pr.status) }}<template v-if="pr.closedDate"> · {{ formatDate(pr.closedDate, false) }}</template> · {{ pr.matchReason }}
                        </span>
                    </span>
                    <button type="button" class="btn btn-secondary btn-sm shrink-0" :disabled="isSubmitting" @click="link(pr.artifactId)">Enlazar</button>
                </div>
            </section>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="repo-select">Repositorio</label>
                    <select id="repo-select" v-model="selectedRepo" class="field" :disabled="isLoadingRepos">
                        <option value="" disabled>{{ isLoadingRepos ? 'Cargando repositorios...' : 'Selecciona un repositorio...' }}</option>
                        <option v-for="repo in repositories" :key="repo.id" :value="repo.id">{{ repo.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="pr-status">Estado del PR</label>
                    <select id="pr-status" v-model="status" class="field">
                        <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="field-label" for="pr-search">Buscar</label>
                <input id="pr-search" v-model="search" type="search" class="field" placeholder="Título, rama o número del PR" :disabled="!selectedRepo" />
            </div>

            <div>
                <label class="field-label" for="pr-select">Solicitud de extracción</label>
                <select id="pr-select" v-model="selectedArtifactId" class="field" :disabled="!selectedRepo || isLoadingPullRequests">
                    <option value="" disabled>
                        {{ isLoadingPullRequests ? 'Cargando solicitudes de extracción...' : pullRequests.length || !selectedRepo ? 'Selecciona una solicitud de extracción...' : 'No hay PRs que coincidan' }}
                    </option>
                    <option v-for="pr in pullRequests" :key="pr.artifactId" :value="pr.artifactId">{{ prLabel(pr) }}</option>
                </select>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-ghost" :disabled="isSubmitting" @click="$emit('close')">Cancelar</button>
            <button type="button" class="btn btn-primary" :disabled="!canSubmit" @click="link(selectedArtifactId)">
                <span v-if="isSubmitting" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">sync</span>
                Vincular a Azure
            </button>
        </template>
    </AppModal>
</template>
