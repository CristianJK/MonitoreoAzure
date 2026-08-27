<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { repositoryService, type Repository, type Branch, type PullRequest } from '../services/repositoryService';

const props = defineProps<{
    workItemId: number;
    isOpen: boolean;
}>();

const emit = defineEmits(['close', 'linked']);

const repositories = ref<Repository[]>([]);
const branches = ref<Branch[]>([]);
const pullRequests = ref<PullRequest[]>([]);
const selectedArtifactId = ref<string>('');
const selectedRepo = ref<string>('');
const selectedPullRequest = ref<string>('');
const isLoadingRepos = ref<boolean>(false);
const isLoadingPullRequests = ref<boolean>(false);
const isSubmitting = ref<boolean>(false);
const errorMessage = ref<string | null>(null);

// Cargar repositorios al abrir el modal
onMounted(async () => {
    try {
        isLoadingRepos.value = true;
        repositories.value = await repositoryService.getRepositories();
    } catch (e) {
        errorMessage.value = 'Error al cargar los repositorios.';
    } finally {
        isLoadingRepos.value = false;
    }
});

// Al cambiar de repositorio, cargamos sus ramas
const onRepoChange = async () => {
    if (!selectedRepo.value) {
        branches.value = [];
        return;
    }
    try {
        isLoadingPullRequests.value = true;
        selectedArtifactId.value = '';
        selectedPullRequest.value = '';
        pullRequests.value = await repositoryService.getPullRequests(selectedRepo.value);
    } catch (e) {
        errorMessage.value = 'Error al cargar las solicitudes de extracción del repositorio.';
    } finally {
        isLoadingPullRequests.value = false;
    }
};

// Enlazar la solicitud de extracción seleccionada al Work Item
const submitLink = async () => {
    if (!selectedRepo.value || !selectedArtifactId.value) return;

    try {
        isSubmitting.value = true;
        errorMessage.value = null;
        
        await repositoryService.linkPullRequest(props.workItemId, selectedArtifactId.value);
        
        emit('linked');
        emit('close');
    } catch (e: any) {
        errorMessage.value = e.response?.data?.message || 'Error al enlazar la solicitud de extracción.';
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="bg-surface-container border border-outline-variant w-full max-w-lg rounded-xl p-6 shadow-xl space-y-6">
            
            <!-- Header -->
            <div class="flex justify-between items-center border-b border-outline-variant pb-4">
                <h3 id="modal-title" class="text-lg font-semibold text-on-surface">Vincular PR a Work Item #{{ workItemId }}</h3>
                <button @click="$emit('close')" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg transition-colors" aria-label="Close modal">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </button>
            </div>

            <!-- Error message -->
            <div v-if="errorMessage" class="p-3 bg-error/10 border border-error/30 text-error text-sm rounded-lg" role="alert">
                {{ errorMessage }}
            </div>

            <!-- Selectores -->
            <div class="space-y-4">
                <!-- Repositorio -->
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase mb-2" for="repo-select">Repositorio</label>
                    <select 
                        id="repo-select"
                        v-model="selectedRepo" 
                        @change="onRepoChange"
                        class="w-full bg-background border border-outline-variant rounded-lg py-2.5 px-3 text-sm text-on-surface focus:outline-none focus:border-primary input-glow"
                    >
                        <option value="" disabled>Selecciona un repositorio...</option>
                        <option v-for="repo in repositories" :key="repo.id" :value="repo.id">
                            {{ repo.name }}
                        </option>
                    </select>
                    <span v-if="isLoadingRepos" class="text-xs text-primary mt-1.5 block">Cargando repositorios...</span>
                </div>

                <!-- Solicitud de Extracción -->
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase mb-2" for="pr-select">Solicitud de Extracción</label>
                    <select 
                        id="pr-select"
                        v-model="selectedArtifactId" 
                        :disabled="!selectedRepo || isLoadingPullRequests"
                        class="w-full bg-background border border-outline-variant rounded-lg py-2.5 px-3 text-sm text-on-surface focus:outline-none focus:border-primary disabled:opacity-50 input-glow"
                    >
                        <option value="" disabled>Selecciona una solicitud de extracción...</option>
                        <option v-for="pr in pullRequests" :key="pr.artifactId" :value="pr.artifactId">
                            {{ pr.title }} ({{ pr.sourceRefName }} → {{ pr.targetRefName }})
                        </option>
                    </select>
                    <span v-if="isLoadingPullRequests" class="text-xs text-primary mt-1.5 block">Cargando solicitudes de extracción...</span>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="flex justify-end gap-3 border-t border-outline-variant pt-4">
                <button 
                    @click="$emit('close')"
                    class="px-4 py-2 rounded-lg bg-surface-variant text-on-surface text-sm hover:bg-surface-bright transition-colors"
                >
                    Cancelar
                </button>
                <button 
                    @click="submitLink"
                    :disabled="!selectedRepo || !selectedArtifactId || isSubmitting"
                    class="px-4 py-2 rounded-lg bg-primary-container text-on-primary-container text-sm font-medium hover:opacity-90 disabled:opacity-50 flex items-center gap-2 transition-opacity"
                >
                    <span v-if="isSubmitting" class="material-symbols-outlined animate-spin text-sm" aria-hidden="true">sync</span>
                    Vincular a Azure
                </button>
            </div>

        </div>
    </div>
</template>