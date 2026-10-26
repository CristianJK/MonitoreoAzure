<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQuery } from 'vue-router';
import { workItemService, type WorkItem, type WorkItemFilters, type WorkItemType } from "@/services/workItemService";
import { checklistService, type ChecklistSummary } from '@/services/checklistService';
import { agentService } from '@/services/agentService';
import { errorMessage } from '@/services/apiClient';
import AppPagination from '@/components/AppPagination.vue';
import CardsWorkItme from '@/components/CardsWorkItme.vue';
import LinkBranchModal from '@/components/LinkBranchModal.vue';
import StateBadge from '@/components/StateBadge.vue';
import UserSearchSelect from '@/components/UserSearchSelect.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { typeColor, typeIcon, initials } from '@/composables/useWorkItemStyles';
import { useToast } from '@/composables/useToast';

const route = useRoute();
const router = useRouter();
const toast = useToast();

// Estado reactivo
const workItems = ref<WorkItem[]>([]);
const checklistSummaries = ref<Record<string, ChecklistSummary>>({});
const isLoading = ref<boolean>(true);
const errorMessageText = ref<string | null>(null);

// Estados para el Modal de Vinculación de Pull Requests
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

const totalPages = ref<number>(1);
const totalItems = ref<number>(0);
const itemsPerPage = ref<number>(10);
const summary = ref({
    done: 0,
    inProgress: 0,
    toDo: 0,
});

// Filtros: viven en la query string para conservarlos al volver del detalle
const asArray = (value: LocationQuery[string]) =>
    (Array.isArray(value) ? value : value ? [value] : []).filter((v): v is string => !!v);

const filters = computed<WorkItemFilters & { assignedLabel: string; page: number }>(() => ({
    states: asArray(route.query.state),
    types: asArray(route.query.type),
    search: (route.query.q as string) || '',
    assignedTo: (route.query.assigned as string) || '@Me',
    assignedLabel: (route.query.assignedLabel as string) || '',
    page: Number(route.query.page) || 1,
}));

const currentPage = computed({
    get: () => filters.value.page,
    set: (page: number) => router.replace({ query: { ...route.query, page: page > 1 ? String(page) : undefined } }),
});

const activeFilterCount = computed(() =>
    filters.value.states!.length + filters.value.types!.length + (filters.value.search ? 1 : 0));

const isOtherUser = computed(() => filters.value.assignedTo!.toLowerCase() !== '@me');

// Panel de filtros (borrador hasta "Aplicar")
const showFilters = ref(false);
const draft = ref({ states: [] as string[], types: [] as string[], search: '' });
const workItemTypes = ref<WorkItemType[]>([]);

const availableStates = computed(() => {
    const types = draft.value.types.length
        ? workItemTypes.value.filter((t) => draft.value.types.includes(t.name))
        : workItemTypes.value.filter((t) => ['Task', 'Bug', 'Product Backlog Item', 'Feature', 'Epic'].includes(t.name));
    return [...new Set(types.flatMap((t) => t.states.map((s) => s.name)))].filter((s) => s !== 'Closed');
});

const toggleFilters = async () => {
    showFilters.value = !showFilters.value;
    if (showFilters.value) {
        draft.value = { states: [...filters.value.states!], types: [...filters.value.types!], search: filters.value.search || '' };
        if (!workItemTypes.value.length) {
            try {
                workItemTypes.value = await workItemService.getWorkItemTypes();
            } catch (e) {
                toast.error(errorMessage(e, 'No se pudieron cargar los tipos de Work Item'));
            }
        }
    }
};

const toggleDraft = (list: string[], value: string) => {
    const index = list.indexOf(value);
    if (index >= 0) list.splice(index, 1);
    else list.push(value);
};

const applyFilters = (values: { states?: string[]; types?: string[]; search?: string }) => {
    router.replace({
        query: {
            ...route.query,
            state: values.states ?? filters.value.states,
            type: values.types ?? filters.value.types,
            q: (values.search ?? filters.value.search) || undefined,
            page: undefined,
        },
    });
};

const applyDraft = () => {
    applyFilters({ ...draft.value, search: draft.value.search.trim() });
    showFilters.value = false;
};

const clearFilters = () => {
    draft.value = { states: [], types: [], search: '' };
    applyFilters({ states: [], types: [], search: '' });
    showFilters.value = false;
};

// Las tarjetas de resumen funcionan como filtro rápido por estado
const quickFilter = (state: string) => {
    const active = filters.value.states!.length === 1 && filters.value.states![0] === state;
    applyFilters({ states: active ? [] : [state] });
};

const isQuickActive = (state: string) => filters.value.states!.length === 1 && filters.value.states![0] === state;

const assignedTo = computed(() => filters.value.assignedTo!);
const assignedLabel = computed(() => filters.value.assignedLabel);

const selectUser = (value: string, label: string) => {
    const isMe = value.toLowerCase() === '@me';
    router.replace({
        query: {
            ...route.query,
            assigned: isMe ? undefined : value,
            assignedLabel: isMe ? undefined : label || undefined,
            page: undefined,
        },
    });
};

// Cargar datos usando el servicio
const loadWorkItems = async () => {
    try {
        isLoading.value = true;
        errorMessageText.value = null;

        const response = await workItemService.getActiveWorkItems(currentPage.value, itemsPerPage.value, filters.value);
        workItems.value = response.data;
        totalPages.value = response.totalPages;
        totalItems.value = response.total;
        summary.value = response.summary;

        checklistService.getSummaries(response.data.map((i) => i.id))
            .then((summaries) => (checklistSummaries.value = summaries))
            .catch(() => (checklistSummaries.value = {}));
    } catch (error) {
        errorMessageText.value = errorMessage(error, 'No se pudieron cargar las tareas. Verifica la conexión con Laravel.');
    } finally {
        isLoading.value = false;
    }
};

watch(() => route.query, (query, previous) => {
    if (route.name === 'dashboard' && JSON.stringify(query) !== JSON.stringify(previous)) loadWorkItems();
});

onMounted(() => {
    loadWorkItems();
});

const openWorkItem = (item: WorkItem) => router.push({ name: 'work-item', params: { id: item.id } });

// "Daily Comment Automation": analiza en lote los WIs abiertos del usuario filtrado
const confirmBatch = ref(false);
const startingBatch = ref(false);

const startBatch = async () => {
    startingBatch.value = true;
    try {
        const { batch_id, count } = await agentService.startBatch(filters.value.assignedTo);
        confirmBatch.value = false;
        if (!count) {
            toast.info('No hay Work Items abiertos para analizar.');
            return;
        }
        toast.info(`Analizando ${count} Work Items...`);
        router.push({ name: 'agent-inbox', query: { batch: batch_id } });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        startingBatch.value = false;
    }
};

const checklistBadge = (id: number) => {
    const s = checklistSummaries.value[String(id)];
    if (!s || !s.total) return null;
    return {
        text: `${s.done}/${s.total}`,
        class: s.ready_to_deploy ? 'text-success border-success/40' : s.blocking_pending ? 'text-warning border-warning/40' : 'text-on-surface-variant border-outline-variant',
        title: s.ready_to_deploy ? 'Listo para producción' : `${s.blocking_pending} bloqueante(s) pendiente(s)`,
    };
};
</script>

<template>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto bg-background">
        <div class="max-w-7xl mx-auto space-y-6">

            <!-- Header Section -->
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                <div>
                    <h1 class="text-headline-lg font-headline-lg text-on-surface">Dashboard Módulo de Gestión</h1>
                    <p class="text-body-sm text-on-surface-variant mt-1">Métricas en tiempo real y tareas activas de Azure DevOps.</p>
                </div>
                <div class="lg:w-80">
                    <UserSearchSelect :value="assignedTo" :label="assignedLabel" @select="selectUser" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <button type="button" class="text-left rounded-xl" :aria-pressed="isQuickActive('Done')" @click="quickFilter('Done')">
                    <CardsWorkItme
                        title="Done"
                        :value="summary.done"
                        :description="isQuickActive('Done') ? 'Filtrando · clic para quitar' : 'Completados'"
                        icon="check_circle"
                        accent-class="bg-success"
                        :class="{ 'ring-2 ring-success/60': isQuickActive('Done') }"
                    />
                </button>
                <button type="button" class="text-left rounded-xl" :aria-pressed="isQuickActive('In Progress')" @click="quickFilter('In Progress')">
                    <CardsWorkItme
                        title="In Progress"
                        :value="summary.inProgress"
                        :description="isQuickActive('In Progress') ? 'Filtrando · clic para quitar' : 'En progreso'"
                        icon="sync"
                        accent-class="bg-primary-container"
                        :class="{ 'ring-2 ring-primary/60': isQuickActive('In Progress') }"
                    />
                </button>
                <button type="button" class="text-left rounded-xl" :aria-pressed="isQuickActive('To Do')" @click="quickFilter('To Do')">
                    <CardsWorkItme
                        title="To Do"
                        :value="summary.toDo"
                        :description="isQuickActive('To Do') ? 'Filtrando · clic para quitar' : 'Pendientes'"
                        icon="assignment"
                        accent-class="bg-secondary"
                        :class="{ 'ring-2 ring-secondary/60': isQuickActive('To Do') }"
                    />
                </button>
            </div>

            <!-- Tabla de Work Items -->
            <div class="bg-surface-container border border-outline-variant rounded-xl overflow-hidden flex flex-col">
                <div class="p-4 border-b border-outline-variant flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 bg-surface-container-high">
                    <h2 class="text-headline-sm font-headline-sm text-on-surface">
                        Work Items
                        <span v-if="isOtherUser" class="text-body-sm font-normal text-on-surface-variant">de {{ assignedLabel || assignedTo }}</span>
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            :aria-expanded="showFilters"
                            aria-controls="filters-panel"
                            @click="toggleFilters"
                        >
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">filter_list</span>
                            Filtros
                            <span v-if="activeFilterCount" class="bg-primary-container text-on-primary-container text-[11px] font-bold rounded-full w-5 h-5 flex items-center justify-center">{{ activeFilterCount }}</span>
                        </button>
                        <button type="button" class="btn btn-outline btn-sm group" @click="confirmBatch = true">
                            <span class="material-symbols-outlined text-[16px] group-hover:animate-spin-slow" aria-hidden="true">auto_awesome</span>
                            Daily Comment Automation
                        </button>
                    </div>
                </div>

                <!-- Panel de filtros -->
                <form
                    v-if="showFilters"
                    id="filters-panel"
                    class="p-4 border-b border-outline-variant bg-surface-container-low grid gap-4 md:grid-cols-[1fr_1fr_1fr]"
                    @submit.prevent="applyDraft"
                >
                    <div>
                        <label for="filter-search" class="field-label">Buscar</label>
                        <input id="filter-search" v-model="draft.search" type="search" class="field" placeholder="ID o título" />
                    </div>
                    <fieldset>
                        <legend class="field-label">Tipo</legend>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="type in workItemTypes.filter((t) => ['Task', 'Bug', 'Product Backlog Item', 'Feature', 'Epic'].includes(t.name))"
                                :key="type.name"
                                type="button"
                                class="px-2.5 py-1 rounded-full border text-[12px] transition-colors"
                                :class="draft.types.includes(type.name) ? 'bg-primary-container/25 border-primary text-primary' : 'border-outline-variant text-on-surface-variant hover:text-on-surface'"
                                :aria-pressed="draft.types.includes(type.name)"
                                @click="toggleDraft(draft.types, type.name)"
                            >
                                {{ type.name }}
                            </button>
                            <span v-if="!workItemTypes.length" class="text-body-sm text-on-surface-variant">Cargando...</span>
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="field-label">Estado</legend>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="state in availableStates"
                                :key="state"
                                type="button"
                                class="px-2.5 py-1 rounded-full border text-[12px] transition-colors"
                                :class="draft.states.includes(state) ? 'bg-primary-container/25 border-primary text-primary' : 'border-outline-variant text-on-surface-variant hover:text-on-surface'"
                                :aria-pressed="draft.states.includes(state)"
                                @click="toggleDraft(draft.states, state)"
                            >
                                {{ state }}
                            </button>
                        </div>
                    </fieldset>
                    <div class="md:col-span-3 flex justify-end gap-2">
                        <button type="button" class="btn btn-ghost btn-sm" @click="clearFilters">Limpiar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
                    </div>
                </form>

                <!-- Headers -->
                <div class="hidden md:grid grid-cols-12 gap-4 px-4 py-2.5 border-b border-outline-variant bg-surface-container-low text-label-caps font-label-caps text-on-surface-variant uppercase">
                    <div class="col-span-1">ID</div>
                    <div class="col-span-5">Título</div>
                    <div class="col-span-2">Estado</div>
                    <div class="col-span-3">Asignado a</div>
                    <div class="col-span-1 text-right">Acciones</div>
                </div>

                <!-- Estados -->
                <div v-if="isLoading" class="p-8 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined animate-spin text-4xl mb-2" aria-hidden="true">sync</span>
                    <p>Cargando datos desde Azure...</p>
                </div>

                <div v-else-if="errorMessageText" class="p-8 text-center text-error bg-error/10 rounded-lg mx-4 my-4">
                    <p>{{ errorMessageText }}</p>
                    <button type="button" class="btn btn-secondary btn-sm mt-3" @click="loadWorkItems">Reintentar</button>
                </div>

                <div v-else-if="!workItems.length" class="p-10 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-5xl" aria-hidden="true">search_off</span>
                    <p class="mt-2 text-on-surface">No hay Work Items con estos filtros.</p>
                    <button v-if="activeFilterCount" type="button" class="btn btn-secondary btn-sm mt-3" @click="clearFilters">Limpiar filtros</button>
                </div>

                <!-- Contenido -->
                <ul v-else class="divide-y divide-outline-variant">
                    <li
                        v-for="item in workItems"
                        :key="item.id"
                        class="grid grid-cols-[auto_1fr_auto] md:grid-cols-12 gap-x-3 md:gap-4 gap-y-2 px-4 py-3 items-center hover:bg-surface-variant transition-colors group cursor-pointer"
                        role="link"
                        tabindex="0"
                        :aria-label="`Work Item ${item.id}: ${item.title}`"
                        @click="openWorkItem(item)"
                        @keydown.enter="openWorkItem(item)"
                    >
                        <div class="md:col-span-1 font-label-mono text-label-mono text-primary">{{ item.id }}</div>

                        <div class="md:col-span-5 flex items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-base shrink-0" :class="typeColor(item.type)" :title="item.type" aria-hidden="true">
                                {{ typeIcon(item.type) }}
                            </span>
                            <span class="text-body-sm text-on-surface truncate" :title="item.title">
                                {{ item.title }}
                            </span>
                            <span
                                v-if="checklistBadge(item.id)"
                                class="shrink-0 text-[10px] font-label-mono border rounded px-1.5 py-px"
                                :class="checklistBadge(item.id)!.class"
                                :title="`Alistamiento: ${checklistBadge(item.id)!.title}`"
                            >
                                {{ checklistBadge(item.id)!.text }}
                            </span>
                        </div>

                        <div class="md:col-span-2 row-start-2 col-start-2 md:row-start-auto md:col-start-auto">
                            <StateBadge :state="item.state" />
                        </div>

                        <div class="hidden md:flex md:col-span-3 items-center gap-2 min-w-0">
                            <div class="w-6 h-6 rounded-full bg-surface-bright flex items-center justify-center text-[10px] font-bold text-on-surface border border-outline-variant shrink-0">
                                {{ initials(item.assigned_to) }}
                            </div>
                            <span class="text-body-sm text-on-surface-variant truncate">{{ item.assigned_to }}</span>
                        </div>

                        <div class="md:col-span-1 flex justify-end row-span-2 md:row-span-1">
                            <button
                                type="button"
                                class="p-1.5 rounded-lg text-on-surface-variant hover:text-primary hover:bg-surface-bright transition-colors"
                                title="Vincular Pull Request"
                                :aria-label="`Vincular Pull Request al work item ${item.id}`"
                                @click.stop="openLinkModal(item.id)"
                                @keydown.enter.stop
                            >
                                <span class="material-symbols-outlined text-lg" aria-hidden="true">merge</span>
                            </button>
                        </div>
                    </li>
                </ul>
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

        <ConfirmModal
            :open="confirmBatch"
            title="Daily Comment Automation"
            confirm-label="Analizar"
            :loading="startingBatch"
            @confirm="startBatch"
            @cancel="confirmBatch = false"
        >
            <p>
                Se revisarán los Work Items abiertos de
                <strong class="text-on-surface">{{ isOtherUser ? (assignedLabel || assignedTo) : 'Yo (@Me)' }}</strong>
                buscando commits de hoy en sus Pull Requests o ramas.
            </p>
            <p>Copilot redactará una propuesta para los que tengan commits. Todas quedan en la bandeja para revisión: nada se publica automáticamente.</p>
        </ConfirmModal>
    </main>
</template>
