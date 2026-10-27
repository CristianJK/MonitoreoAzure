<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ChecklistDefinitionFormModal from '@/components/ChecklistDefinitionFormModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import {
    checklistService,
    type ChecklistDefinition,
    type ChecklistSource,
    type DataType,
    type Option,
} from '@/services/checklistService';
import { workItemService, type WorkItemType } from '@/services/workItemService';
import { errorMessage } from '@/services/apiClient';
import { useToast } from '@/composables/useToast';
import { useSession } from '@/composables/useSession';

const toast = useToast();
// El catálogo es de solo lectura salvo para los administradores (AUTH_ADMINS)
const { isAdmin } = useSession();

const definitions = ref<ChecklistDefinition[]>([]);
const types = ref<WorkItemType[]>([]);
const dataTypes = ref<Option<DataType>[]>([]);
const sources = ref<Option<ChecklistSource>[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const filterState = ref('');
const filterType = ref('');

const formOpen = ref(false);
const editing = ref<ChecklistDefinition | null>(null);
const defaultState = ref<string | undefined>();
const deleting = ref<ChecklistDefinition | null>(null);
const deletingBusy = ref(false);

const load = async () => {
    loading.value = true;
    error.value = null;
    try {
        const [defs, wiTypes, catalog] = await Promise.all([
            checklistService.getDefinitions(),
            workItemService.getWorkItemTypes(),
            checklistService.getDataTypes(),
        ]);
        definitions.value = defs;
        types.value = wiTypes;
        dataTypes.value = catalog.data_types;
        sources.value = catalog.sources;
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo cargar el alistamiento');
    } finally {
        loading.value = false;
    }
};

onMounted(load);

// Orden del flujo: primero los estados de los tipos más comunes, en el orden de Azure
const stateOrder = computed(() => {
    const order: string[] = [];
    const preferred = ['Task', 'Product Backlog Item', 'Bug'];
    const sorted = [...types.value].sort((a, b) => {
        const ia = preferred.indexOf(a.name);
        const ib = preferred.indexOf(b.name);
        return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
    });
    for (const type of sorted) {
        for (const state of type.states) {
            if (!order.includes(state.name)) order.push(state.name);
        }
    }
    return order;
});

const stateColor = (state: string) => {
    for (const type of types.value) {
        const found = type.states.find((s) => s.name === state);
        if (found) return found.color;
    }
    return 'var(--color-outline-variant)';
};

const filtered = computed(() => definitions.value.filter((d) =>
    (!filterState.value || d.work_item_state === filterState.value)
    && (!filterType.value || d.applies_to_type === null || d.applies_to_type === filterType.value),
));

const groups = computed(() => {
    const byState = new Map<string, ChecklistDefinition[]>();
    for (const definition of filtered.value) {
        if (!byState.has(definition.work_item_state)) byState.set(definition.work_item_state, []);
        byState.get(definition.work_item_state)!.push(definition);
    }
    const rank = (state: string) => {
        const index = stateOrder.value.indexOf(state);
        return index < 0 ? Number.MAX_SAFE_INTEGER : index;
    };
    return [...byState.entries()]
        .sort(([a], [b]) => rank(a) - rank(b))
        .map(([state, items]) => ({ state, items }));
});

const usedStates = computed(() => [...new Set(definitions.value.map((d) => d.work_item_state))]
    .sort((a, b) => stateOrder.value.indexOf(a) - stateOrder.value.indexOf(b)));

const openCreate = (state?: string) => {
    editing.value = null;
    defaultState.value = state;
    formOpen.value = true;
};

const openEdit = (definition: ChecklistDefinition) => {
    editing.value = definition;
    formOpen.value = true;
};

const onSaved = (saved: ChecklistDefinition) => {
    const index = definitions.value.findIndex((d) => d.id === saved.id);
    if (index >= 0) {
        definitions.value[index] = { ...definitions.value[index], ...saved };
    } else {
        definitions.value.push({ ...saved, values_count: 0 });
    }
    definitions.value.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
};

const confirmDelete = async () => {
    if (!deleting.value) return;
    deletingBusy.value = true;
    try {
        await checklistService.deleteDefinition(deleting.value.id);
        definitions.value = definitions.value.filter((d) => d.id !== deleting.value!.id);
        toast.success('Item eliminado');
        deleting.value = null;
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        deletingBusy.value = false;
    }
};

const move = async (group: ChecklistDefinition[], index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= group.length) return;
    const reordered = [...group];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    const items = reordered.map((d, i) => ({ id: d.id, sort_order: i }));
    for (const { id, sort_order } of items) {
        const definition = definitions.value.find((d) => d.id === id);
        if (definition) definition.sort_order = sort_order;
    }
    definitions.value.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    try {
        await checklistService.reorder(items);
    } catch (e) {
        toast.error(errorMessage(e));
        load();
    }
};

const sourceIcon = (source: ChecklistSource) => (source === 'manual' ? 'edit_note' : 'sync');
</script>

<template>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto bg-background">
        <div class="max-w-6xl mx-auto space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h1 class="text-headline-lg font-headline-lg text-on-surface">Alistamiento</h1>
                    <p class="text-body-sm text-on-surface-variant mt-1">
                        Items que se deben cumplir en cada estado del Work Item antes del paso a producción.
                    </p>
                </div>
                <button v-if="isAdmin" type="button" class="btn btn-primary" :disabled="loading" @click="openCreate()">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span>
                    Nuevo item
                </button>
            </div>

            <!-- Filtros -->
            <div class="flex flex-col sm:flex-row gap-3 bg-surface-container border border-outline-variant rounded-xl p-4">
                <div class="sm:w-56">
                    <label for="filter-state" class="field-label">Estado</label>
                    <select id="filter-state" v-model="filterState" class="field">
                        <option value="">Todos</option>
                        <option v-for="state in usedStates" :key="state" :value="state">{{ state }}</option>
                    </select>
                </div>
                <div class="sm:w-56">
                    <label for="filter-type" class="field-label">Tipo de Work Item</label>
                    <select id="filter-type" v-model="filterType" class="field">
                        <option value="">Todos</option>
                        <option v-for="type in types" :key="type.name" :value="type.name">{{ type.name }}</option>
                    </select>
                </div>
            </div>

            <div v-if="loading" class="p-8 text-center text-on-surface-variant">
                <span class="material-symbols-outlined animate-spin text-4xl" aria-hidden="true">progress_activity</span>
                <p class="mt-2">Cargando alistamiento...</p>
            </div>

            <div v-else-if="error" class="p-6 text-center text-error bg-error/10 border border-error/30 rounded-xl">
                <p>{{ error }}</p>
                <button type="button" class="btn btn-secondary mt-3" @click="load">Reintentar</button>
            </div>

            <div v-else-if="!groups.length" class="p-10 text-center bg-surface-container border border-outline-variant rounded-xl">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant" aria-hidden="true">checklist</span>
                <p class="text-on-surface mt-2">
                    {{ definitions.length ? 'Ningún item coincide con los filtros.' : 'Todavía no hay items de alistamiento.' }}
                </p>
                <button v-if="!definitions.length && isAdmin" type="button" class="btn btn-primary mt-4" @click="openCreate()">Crear el primero</button>
            </div>

            <!-- Grupos por estado -->
            <section
                v-for="group in groups"
                v-else
                :key="group.state"
                class="bg-surface-container border border-outline-variant rounded-xl overflow-hidden"
            >
                <header class="flex items-center justify-between gap-3 px-4 py-3 bg-surface-container-high border-b border-outline-variant">
                    <h2 class="flex items-center gap-2 text-headline-sm font-headline-sm text-on-surface">
                        <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: stateColor(group.state) }" aria-hidden="true"></span>
                        {{ group.state }}
                        <span class="text-body-sm font-normal text-on-surface-variant">({{ group.items.length }})</span>
                    </h2>
                    <button v-if="isAdmin" type="button" class="btn btn-ghost btn-sm" @click="openCreate(group.state)">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">add</span>
                        Agregar
                    </button>
                </header>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] table-fixed text-body-sm">
                        <thead class="text-left text-label-caps font-label-caps uppercase text-on-surface-variant bg-surface-container-low">
                            <tr>
                                <th scope="col" class="px-4 py-2 w-16"><span class="sr-only">Orden</span></th>
                                <th scope="col" class="px-4 py-2">Nombre</th>
                                <th scope="col" class="px-4 py-2 w-60">Tipo de dato</th>
                                <th scope="col" class="px-4 py-2 w-44">Aplica a</th>
                                <th scope="col" class="px-4 py-2 w-28 text-center">Bloqueante</th>
                                <th scope="col" class="px-4 py-2 w-28 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant">
                            <tr v-for="(definition, index) in group.items" :key="definition.id" class="hover:bg-surface-variant/50">
                                <td class="px-4 py-2.5">
                                    <div v-if="isAdmin" class="flex flex-col">
                                        <button type="button" class="btn-ghost rounded leading-none disabled:opacity-30" :disabled="index === 0" :aria-label="`Subir ${definition.label}`" @click="move(group.items, index, -1)">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">keyboard_arrow_up</span>
                                        </button>
                                        <button type="button" class="btn-ghost rounded leading-none disabled:opacity-30" :disabled="index === group.items.length - 1" :aria-label="`Bajar ${definition.label}`" @click="move(group.items, index, 1)">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">keyboard_arrow_down</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="block font-semibold text-on-surface truncate" :title="definition.label">{{ definition.label }}</span>
                                    <span class="block text-[11px] text-on-surface-variant font-label-mono">{{ definition.code }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-on-surface">
                                    <span class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant" :title="definition.source_label" aria-hidden="true">{{ sourceIcon(definition.source) }}</span>
                                        {{ definition.data_type_label }}
                                    </span>
                                    <span v-if="definition.source !== 'manual'" class="block text-[11px] text-primary">{{ definition.source_label }}</span>
                                    <span v-if="definition.options.length" class="block text-[11px] text-on-surface-variant truncate max-w-48">{{ definition.options.join(', ') }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-on-surface-variant">{{ definition.applies_to_type || 'Todos' }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span v-if="definition.blocking" class="material-symbols-outlined text-warning text-[18px]" title="Bloqueante" aria-label="Sí">lock</span>
                                    <span v-else class="text-on-surface-variant" aria-label="No">—</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div v-if="isAdmin" class="flex justify-end gap-1">
                                        <button type="button" class="btn-ghost p-1.5 rounded-lg" :aria-label="`Editar ${definition.label}`" title="Editar" @click="openEdit(definition)">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                        </button>
                                        <button type="button" class="p-1.5 rounded-lg text-on-surface-variant hover:text-error hover:bg-error/10" :aria-label="`Eliminar ${definition.label}`" title="Eliminar" @click="deleting = definition">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ChecklistDefinitionFormModal
            :open="formOpen"
            :definition="editing"
            :types="types"
            :data-types="dataTypes"
            :sources="sources"
            :default-state="defaultState"
            @close="formOpen = false"
            @saved="onSaved"
        />

        <ConfirmModal
            :open="!!deleting"
            title="Eliminar item de alistamiento"
            confirm-label="Eliminar"
            danger
            :loading="deletingBusy"
            @confirm="confirmDelete"
            @cancel="deleting = null"
        >
            <p>¿Eliminar <strong class="text-on-surface">{{ deleting?.label }}</strong>?</p>
            <p v-if="deleting?.values_count">
                Hay {{ deleting.values_count }} Work Item(s) con valores registrados. Esos valores se conservan como historial,
                pero el item ya no aparecerá en otros Work Items.
            </p>
        </ConfirmModal>
    </main>
</template>
