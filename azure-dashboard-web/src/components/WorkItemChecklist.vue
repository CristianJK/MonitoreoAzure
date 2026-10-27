<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ChecklistValueModal from './ChecklistValueModal.vue';
import { checklistService, STATUS_LABELS, type ChecklistItem, type ChecklistSummary, type WorkItemChecklist } from '@/services/checklistService';
import { errorMessage } from '@/services/apiClient';
import { formatDate } from '@/composables/useWorkItemStyles';

const props = defineProps<{
    workItemId: number;
    // Cambia cuando el WI cambia de estado, para recargar el alistamiento
    state?: string;
    // WI de otra persona: se muestra el avance pero no se registran valores
    readonly?: boolean;
}>();

const emit = defineEmits<{
    loaded: [checklist: WorkItemChecklist];
}>();

const checklist = ref<WorkItemChecklist | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const selected = ref<ChecklistItem | null>(null);
const collapsed = ref<Record<string, boolean>>({});

const load = async () => {
    loading.value = true;
    error.value = null;
    try {
        checklist.value = await checklistService.getWorkItemChecklist(props.workItemId);
        const currentOrder = checklist.value.groups.find((g) => g.is_current)?.order ?? -1;
        // Los estados anteriores al actual se muestran colapsados
        collapsed.value = Object.fromEntries(
            checklist.value.groups.map((g) => [g.state, g.order !== null && currentOrder >= 0 && g.order < currentOrder]),
        );
        emit('loaded', checklist.value);
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo cargar el alistamiento');
    } finally {
        loading.value = false;
    }
};

watch(() => [props.workItemId, props.state], load, { immediate: true });

const progress = computed(() => {
    const s = checklist.value?.summary;
    return s && s.total ? Math.round((s.done / s.total) * 100) : 0;
});

const onSaved = (item: ChecklistItem, summary: ChecklistSummary) => {
    if (!checklist.value) return;
    for (const group of checklist.value.groups) {
        const index = group.items.findIndex((i) => i.definition_id === item.definition_id);
        if (index >= 0) group.items[index] = { ...group.items[index], ...item, links: group.items[index].links };
    }
    checklist.value.summary = summary;
    emit('loaded', checklist.value);
};

const statusIcon = (item: ChecklistItem) => ({
    done: { icon: 'check_circle', class: 'text-success' },
    skipped: { icon: 'remove_circle', class: 'text-on-surface-variant' },
    failed: { icon: 'warning', class: 'text-warning' },
    in_progress: { icon: 'pending', class: 'text-primary' },
    pending: { icon: 'radio_button_unchecked', class: 'text-on-surface-variant' },
}[item.status]);

const displayValue = (item: ChecklistItem) => {
    if (!item.value) return item.is_automatic ? 'Sin información en Azure' : STATUS_LABELS[item.status];
    switch (item.data_type) {
        case 'boolean':
            return item.value === '1' ? 'Sí' : 'No';
        case 'date':
            return formatDate(item.value + 'T00:00:00', false);
        case 'sharepoint_link':
            return 'Abrir en SharePoint';
        case 'url':
            return item.value.replace(/^https?:\/\//, '');
        default:
            return item.value;
    }
};

const externalLink = (item: ChecklistItem) => {
    if (item.links.length) return item.links[0].url;
    if ((item.data_type === 'sharepoint_link' || item.data_type === 'url') && item.value) return item.value;
    return null;
};

defineExpose({ reload: load });
</script>

<template>
    <div class="flex-1 p-4 border-b border-outline-variant bg-surface-container-high relative">
        <div class="absolute top-0 left-0 w-1 h-full bg-primary" aria-hidden="true"></div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-label-caps text-label-caps uppercase text-primary flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">fact_check</span>
                Alistamiento
            </h3>
            <button type="button" class="btn-ghost p-1 rounded" title="Recargar desde Azure" aria-label="Recargar alistamiento" :disabled="loading" @click="load">
                <span class="material-symbols-outlined text-[16px]" :class="{ 'animate-spin': loading }" aria-hidden="true">refresh</span>
            </button>
        </div>

        <div v-if="loading && !checklist" class="text-body-sm text-on-surface-variant">Cargando alistamiento...</div>
        <div v-else-if="error" class="text-body-sm text-error">{{ error }}</div>

        <template v-else-if="checklist">
            <!-- Progreso -->
            <div class="mb-4">
                <div class="flex justify-between text-[11px] text-on-surface-variant mb-1">
                    <span>{{ checklist.summary.done }} de {{ checklist.summary.total }} completados</span>
                    <span v-if="checklist.summary.ready_to_deploy" class="text-success font-semibold flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">rocket_launch</span>
                        Listo para producción
                    </span>
                    <span v-else-if="checklist.summary.blocking_pending" class="text-warning">
                        {{ checklist.summary.blocking_pending }} bloqueante(s) pendiente(s)
                    </span>
                </div>
                <div class="h-1.5 rounded-full bg-surface-container-lowest overflow-hidden" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-success transition-all" :style="{ width: progress + '%' }"></div>
                </div>
            </div>

            <p v-if="!checklist.groups.length" class="text-body-sm text-on-surface-variant">
                No hay items de alistamiento para el tipo {{ checklist.work_item.type }}.
                <router-link :to="{ name: 'checklist-definitions' }" class="text-primary hover:underline">Configurarlos</router-link>
            </p>

            <!-- Grupos por estado -->
            <div class="flex flex-col gap-3">
                <section v-for="group in checklist.groups" :key="group.state">
                    <button
                        type="button"
                        class="w-full flex items-center justify-between gap-2 py-1 text-left"
                        :aria-expanded="!collapsed[group.state]"
                        @click="collapsed[group.state] = !collapsed[group.state]"
                    >
                        <span class="flex items-center gap-2 text-body-sm font-semibold" :class="group.is_current ? 'text-on-surface' : 'text-on-surface-variant'">
                            <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: group.color || 'var(--color-outline-variant)' }" aria-hidden="true"></span>
                            {{ group.state }}
                            <span v-if="group.is_current" class="text-[10px] uppercase tracking-wide bg-primary/15 text-primary px-1.5 py-0.5 rounded">Actual</span>
                            <span v-if="group.order === null" class="text-[10px] text-on-surface-variant font-normal">(no existe para este tipo)</span>
                        </span>
                        <span class="flex items-center gap-1 text-[11px] text-on-surface-variant">
                            {{ group.items.filter((i) => i.status === 'done' || i.status === 'skipped').length }}/{{ group.items.length }}
                            <span class="material-symbols-outlined text-[16px] transition-transform" :class="{ '-rotate-90': collapsed[group.state] }" aria-hidden="true">expand_more</span>
                        </span>
                    </button>

                    <ul v-show="!collapsed[group.state]" class="flex flex-col gap-1.5 mt-1.5">
                        <li v-for="item in group.items" :key="item.definition_id">
                            <div
                                class="flex items-start gap-2.5 p-2.5 rounded-lg border transition-colors"
                                :class="[
                                    group.is_current ? 'bg-surface-container border-outline-variant' : 'bg-surface-container-low border-outline-variant/60',
                                    item.is_deleted ? 'opacity-60' : '',
                                ]"
                            >
                                <span class="material-symbols-outlined text-[18px] shrink-0 mt-px" :class="statusIcon(item).class" aria-hidden="true">{{ statusIcon(item).icon }}</span>
                                <button
                                    type="button"
                                    class="flex-1 min-w-0 text-left rounded focus-visible:outline-2 disabled:cursor-default"
                                    :aria-label="readonly ? `${item.label}: ${displayValue(item)}` : `${item.label}: ${displayValue(item)}. Abrir para registrar valor`"
                                    :disabled="readonly"
                                    @click="selected = item"
                                >
                                    <span class="flex items-center gap-1.5 text-body-sm font-semibold text-on-surface leading-tight">
                                        <span class="truncate">{{ item.label }}</span>
                                        <span v-if="item.blocking" class="material-symbols-outlined text-[13px] text-warning" title="Bloqueante" aria-label="Bloqueante">lock</span>
                                        <span v-if="item.is_automatic" class="material-symbols-outlined text-[13px] text-primary" title="Automático desde Azure" aria-label="Automático">sync</span>
                                    </span>
                                    <span class="block text-[11px] truncate mt-0.5" :class="item.value ? 'text-on-surface-variant' : 'text-on-surface-variant/70 italic'">
                                        {{ displayValue(item) }}
                                        <template v-if="item.is_deleted"> · eliminado del catálogo</template>
                                    </span>
                                </button>
                                <a
                                    v-if="externalLink(item)"
                                    :href="externalLink(item)!"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-on-surface-variant hover:text-primary p-0.5 rounded shrink-0"
                                    :title="`Abrir ${item.label}`"
                                    :aria-label="`Abrir ${item.label} en otra pestaña`"
                                >
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">open_in_new</span>
                                </a>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <ChecklistValueModal
            :open="!!selected"
            :work-item-id="workItemId"
            :item="selected"
            @close="selected = null"
            @saved="onSaved"
        />
    </div>
</template>
