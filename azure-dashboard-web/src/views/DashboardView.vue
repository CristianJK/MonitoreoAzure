<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { workItemService, type WorkItem } from "@/services/workItemService";
import AppPagination from '@/components/AppPagination.vue';
import CardsWorkItme from '@/components/CardsWorkItme.vue';
import LinkBranchModal from '@/components/LinkBranchModal.vue';

// Estado reactivo
const workItems = ref<WorkItem[]>([]);
const isLoading = ref<boolean>(true);
const errorMessage = ref<string | null>(null);

// Estados para el Modal de Vinculación de Ramas
const isModalOpen = ref<boolean>(false);
const activeWorkItemId = ref<number | null>(null);


// Abrir el modal para una tarea específica
const openLinkModal = (id: number) => {
    activeWorkItemId.value = id;
    isModalOpen.value = true;
};

// Cerrar y limpiar
const closeModal = () => {
    isModalOpen.value = false;
    activeWorkItemId.value = null;
};

//paginacion

const currentPage = ref<number>(1);
const totalPages = ref<number>(1);
const totalItems = ref<number>(0);
const itemsPerPage = ref<number>(10);
const summary = ref({
    done: 0,
    inProgress: 0,
    toDo: 0,
});

// Cargar datos usando el servicio
const loadWorkItems = async () => {
    try {
        isLoading.value = true;
        errorMessage.value = null;

        const response = await workItemService.getActiveWorkItems(
            currentPage.value,
            itemsPerPage.value
        );
        console.log(response);
        workItems.value = response.data;
        totalPages.value = response.totalPages;
        totalItems.value = response.total;
        summary.value = response.summary;
    } catch (error) {
        console.error('Error al obtener los Work Items:', error);
        errorMessage.value = 'No se pudieron cargar las tareas. Verifica la conexión con Laravel.';
    } finally {
        isLoading.value = false;
    }
};

watch(currentPage, () => {
    loadWorkItems();
});


// Helpers visuales
const getIconByType = (type: string) => {
    if (type.toLowerCase().includes('bug')) return 'bug_report';
    if (type.toLowerCase().includes('product')) return 'inventory_2';
    return 'task';
};

const getColorByType = (type: string) => {
    if (type.toLowerCase().includes('bug')) return 'text-error';
    if (type.toLowerCase().includes('product')) return 'text-secondary';
    return 'text-primary';
};

onMounted(() => {
    loadWorkItems();
});
</script>

<template>
    <main class="flex-1 md:ml-64 pt-16 p-6 overflow-y-auto min-h-screen bg-background">
        <div class="max-w-7xl mx-auto space-y-8">
            
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold text-on-surface">Dashboard Módulo de Gestión</h1>
                    <p class="text-sm text-on-surface-variant mt-1">Métricas en tiempo real y tareas activas de Azure DevOps.</p>
                </div>
                <div class="flex gap-3">
                    <button class="bg-surface-variant border border-outline-variant text-on-surface px-4 py-2 rounded-md font-body-sm text-body-sm hover:bg-surface-bright transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">filter_list</span>
                                            Filter
                                        </button>
                    <button class="bg-primary-container text-on-primary-container px-4 py-2 rounded-md font-body-sm text-body-sm hover:opacity-90 transition-opacity flex items-center gap-2 shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">add</span>
                                            New Item
                                        </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <CardsWorkItme
                    title="Done"
                    :value="summary.done"
                    description="Asignados a ti"
                    icon="check_circle"
                    accent-class="bg-success"
                />
                <CardsWorkItme
                    title="In Progress"
                    :value="summary.inProgress"
                    description="Asignados a ti"
                    icon="sync"
                    accent-class="bg-primary-container"
                />
                <CardsWorkItme
                    title="To Do"
                    :value="summary.toDo"
                    description="Asignados a ti"
                    icon="assignment"
                    accent-class="bg-secondary"
                />
            </div>

            <!-- Tabla de Work Items -->
            <div class="bg-surface-container border border-outline-variant rounded-lg overflow-hidden flex flex-col">
                <div class="p-4 border-b border-outline-variant flex justify-between items-center bg-surface-container-high">
                    <h2 class="text-lg font-medium text-on-surface">Work Items</h2>
                    <button class="bg-surface-variant border border-primary text-primary px-3 py-1.5 rounded-md font-body-sm text-body-sm hover:bg-primary/10 transition-colors flex items-center gap-2 group">
                        <span class="material-symbols-outlined text-[16px] group-hover:animate-spin-slow">auto_awesome</span>
                        Daily Comment Automation
                    </button>
                </div>

                <!-- Headers -->
                <div class="grid grid-cols-12 gap-4 px-4 py-2 border-b border-outline-variant bg-surface-container-low text-xs font-bold text-on-surface-variant uppercase">
                    <div class="col-span-1">ID</div>
                    <div class="col-span-5">Title</div>
                    <div class="col-span-2">State</div>
                    <div class="col-span-3">Assigned To</div>
                    <div class="col-span-1 text-right">Actions</div>
                </div>

                <!-- Estados -->
                <div v-if="isLoading" class="p-8 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined animate-spin text-4xl mb-2">sync</span>
                    <p>Cargando datos desde Azure...</p>
                </div>

                <div v-else-if="errorMessage" class="p-8 text-center text-error bg-error/10">
                    <p>{{ errorMessage }}</p>
                </div>

                <!-- Contenido -->
                <div v-else class="divide-y divide-outline-variant">
                    <div 
                        v-for="item in workItems" 
                        :key="item.id"
                        class="grid grid-cols-12 gap-4 px-4 py-3 items-center hover:bg-surface-variant transition-colors group"
                    >
                        <div class="col-span-1 font-mono text-sm text-primary">{{ item.id }}</div>
                        
                        <div class="col-span-5 flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" :class="getColorByType(item.type)">
                                {{ getIconByType(item.type) }}
                            </span>
                            <span class="text-sm text-on-surface truncate" :title="item.title">
                                {{ item.title }}
                            </span>
                        </div>
                        
                        <div class="col-span-2">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-primary-container/20 text-primary border border-primary/30 text-xs">
                                <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
                                {{ item.state }}
                            </span>
                        </div>
                        
                        <div class="col-span-3 flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full bg-surface-bright flex items-center justify-center text-xs font-bold text-on-surface border border-outline-variant">
                                {{ item.assigned_to.charAt(0) }}
                            </div>
                            <span class="text-sm text-on-surface-variant truncate">{{ item.assigned_to }}</span>
                        </div>
                        
                        <div class="col-span-1 flex justify-end">
                            <button class="p-1 rounded text-on-surface-variant hover:text-primary hover:bg-surface-bright transition-colors" title="Vincular Rama"
                                @click="openLinkModal(item.id)"
                            >
                                <span class="material-symbols-outlined text-lg">account_tree</span>
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Paginación -->
                <AppPagination 
                    v-model:page="currentPage" 
                    :total-pages="totalPages" 
                    :total-items="totalItems"
                    :items-per-page="itemsPerPage"
                    :is-loading="isLoading"
                />
            </div>
        </div>
        <!-- Componente Modal integrado -->
        <LinkBranchModal 
            v-if="activeWorkItemId !== null"
            :isOpen="isModalOpen" 
            :workItemId="activeWorkItemId" 
            @close="closeModal"
            @linked="loadWorkItems"
        />
    </main>
</template>