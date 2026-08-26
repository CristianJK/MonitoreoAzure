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
    <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-surface-container border border-outline-variant w-full max-w-lg rounded-lg p-6 shadow-xl space-y-6">
            
            <!-- Header -->
            <div class="flex justify-between items-center border-b border-outline-variant pb-4">
                <h3 class="text-lg font-semibold text-on-surface">Vincular Solicitud de Extracción a Taker #{{ workItemId }}</h3>
                <button @click="$emit('close')" class="text-on-surface-variant hover:text-on-surface">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <!-- Error message -->
            <div v-if="errorMessage" class="p-3 bg-error/10 border border-error/30 text-error text-sm rounded">
                {{ errorMessage }}
            </div>

            <!-- Selectores -->
            <div class="space-y-4">
                <!-- Repositorio -->
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase mb-2">Repositorio</label>
                    <select 
                        v-model="selectedRepo" 
                        @change="onRepoChange"
                        class="w-full bg-background border border-outline-variant rounded-md py-2 px-3 text-sm text-on-surface focus:outline-none focus:border-primary"
                    >
                        <option value="" disabled>Selecciona un repositorio...</option>
                        <option v-for="repo in repositories" :key="repo.id" :value="repo.id">
                            {{ repo.name }}
                        </option>
                    </select>
                    <span v-if="isLoadingRepos" class="text-xs text-primary mt-1 block">Cargando repositorios...</span>
                </div>

                <!-- Solicitud de Extracción -->
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase mb-2">Solicitud de Extracción</label>
                    <select 
                        v-model="selectedArtifactId" 
                        :disabled="!selectedRepo || isLoadingPullRequests"
                        class="w-full bg-background border border-outline-variant rounded-md py-2 px-3 text-sm text-on-surface focus:outline-none focus:border-primary disabled:opacity-50"
                    >
                        <option value="" disabled>Selecciona una solicitud de extracción...</option>
                        <option v-for="pr in pullRequests" :key="pr.artifactId" :value="pr.artifactId">
                            {{ pr.title }} ({{ pr.sourceRefName }} → {{ pr.targetRefName }})
                        </option>
                    </select>
                    <span v-if="isLoadingPullRequests" class="text-xs text-primary mt-1 block">Cargando solicitudes de extracción...</span>
                </div>
            </div>

            <!-- Footer Buttons -->
            <div class="flex justify-end gap-3 border-t border-outline-variant pt-4">
                <button 
                    @click="$emit('close')"
                    class="px-4 py-2 rounded bg-surface-variant text-on-surface text-sm hover:bg-surface-bright transition-colors"
                >
                    Cancelar
                </button>
                <button 
                    @click="submitLink"
                    :disabled="!selectedRepo || !selectedArtifactId || isSubmitting"
                    class="px-4 py-2 rounded bg-primary-container text-on-primary-container text-sm font-medium hover:opacity-90 disabled:opacity-50 flex items-center gap-2"
                >
                    <span v-if="isSubmitting" class="material-symbols-outlined animate-spin text-sm">sync</span>
                    Vincular a Azure
                </button>
            </div>

        </div>
    </div>
</template>