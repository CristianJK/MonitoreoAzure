<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import { checklistService, STATUS_LABELS, type ChecklistItem, type ChecklistItemStatus, type ChecklistSummary } from '@/services/checklistService';
import { errorMessage, validationErrors } from '@/services/apiClient';
import { formatDate } from '@/composables/useWorkItemStyles';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    open: boolean;
    workItemId: number;
    item: ChecklistItem | null;
}>();

const emit = defineEmits<{
    close: [];
    saved: [item: ChecklistItem, summary: ChecklistSummary];
}>();

const toast = useToast();
const value = ref<string>('');
const status = ref<ChecklistItemStatus | ''>('');
const notes = ref('');
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

const SHAREPOINT_PATTERN = /^https:\/\/[^/]+\.sharepoint\.com(\/|$)/i;

watch(() => [props.open, props.item] as const, ([open, item]) => {
    if (!open || !item) return;
    value.value = item.value ?? (item.data_type === 'boolean' ? '' : '');
    status.value = item.value || item.status !== 'pending' ? item.status : '';
    notes.value = item.notes ?? '';
    errors.value = {};
}, { immediate: true });

const isUrl = computed(() => props.item?.data_type === 'url' || props.item?.data_type === 'sharepoint_link');

const localError = computed(() => {
    if (!props.item || !value.value) return '';
    if (props.item.data_type === 'sharepoint_link' && !SHAREPOINT_PATTERN.test(value.value)) {
        return 'Debe ser un link de SharePoint (https://<empresa>.sharepoint.com/...).';
    }
    if (props.item.data_type === 'url' && !/^https?:\/\//i.test(value.value)) {
        return 'Debe ser una URL que empiece por http:// o https://';
    }
    return '';
});

const save = async () => {
    if (!props.item || localError.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            value: props.item.data_type === 'boolean'
                ? (value.value === '' ? null : value.value === '1')
                : (value.value.trim() === '' ? null : value.value.trim()),
            status: status.value || null,
            notes: notes.value.trim() || null,
        };
        const result = await checklistService.saveValue(props.workItemId, props.item.definition_id, payload);
        toast.success(`"${props.item.label}" actualizado`);
        emit('saved', result.item, result.summary);
        emit('close');
    } catch (e) {
        errors.value = validationErrors(e);
        if (!Object.keys(errors.value).length) toast.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <AppModal
        :open="open && !!item"
        :title="item?.label ?? ''"
        :subtitle="item ? `Etapa: ${item.work_item_state}${item.blocking ? ' · Bloqueante' : ''}` : ''"
        @close="emit('close')"
    >
        <form v-if="item" id="checklist-value-form" class="space-y-4" @submit.prevent="save">
            <p v-if="item.description" class="text-body-sm text-on-surface-variant">{{ item.description }}</p>

            <!-- Items automáticos: solo lectura -->
            <div v-if="item.is_automatic" class="space-y-3">
                <div class="flex items-start gap-2 p-3 rounded-lg bg-primary/10 border border-primary/30 text-body-sm text-on-surface">
                    <span class="material-symbols-outlined text-primary text-[18px]" aria-hidden="true">sync</span>
                    Este item se calcula automáticamente desde Azure DevOps cada vez que se abre el Work Item.
                </div>
                <div>
                    <span class="field-label">Valor actual</span>
                    <p class="text-body-sm text-on-surface">{{ item.value || 'Sin información todavía' }}</p>
                </div>
                <ul v-if="item.links.length" class="space-y-1">
                    <li v-for="link in item.links" :key="link.url">
                        <a :href="link.url" target="_blank" rel="noopener noreferrer" class="text-primary text-body-sm hover:underline inline-flex items-center gap-1">
                            {{ link.label }}
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">open_in_new</span>
                        </a>
                    </li>
                </ul>
            </div>

            <template v-else>
                <div>
                    <label for="checklist-value" class="field-label">Valor</label>

                    <input v-if="item.data_type === 'text'" id="checklist-value" v-model="value" type="text" maxlength="500" class="field" autofocus />

                    <textarea v-else-if="item.data_type === 'textarea'" id="checklist-value" v-model="value" rows="5" class="field resize-y" autofocus></textarea>

                    <input v-else-if="item.data_type === 'number'" id="checklist-value" v-model="value" type="number" step="any" class="field" autofocus />

                    <input v-else-if="item.data_type === 'date'" id="checklist-value" v-model="value" type="date" class="field" autofocus />

                    <div v-else-if="item.data_type === 'boolean'" id="checklist-value" class="flex gap-2" role="radiogroup">
                        <button
                            v-for="option in [{ v: '1', l: 'Sí' }, { v: '0', l: 'No' }]"
                            :key="option.v"
                            type="button"
                            role="radio"
                            :aria-checked="value === option.v"
                            class="btn flex-1"
                            :class="value === option.v ? 'btn-primary' : 'btn-secondary'"
                            @click="value = value === option.v ? '' : option.v"
                        >
                            {{ option.l }}
                        </button>
                    </div>

                    <select v-else-if="item.data_type === 'select'" id="checklist-value" v-model="value" class="field" autofocus>
                        <option value="">Selecciona una opción...</option>
                        <option v-for="option in item.options" :key="option" :value="option">{{ option }}</option>
                    </select>

                    <div v-else-if="isUrl" class="flex gap-2">
                        <input
                            id="checklist-value"
                            v-model="value"
                            type="url"
                            class="field"
                            :placeholder="item.data_type === 'sharepoint_link' ? 'https://empresa.sharepoint.com/sites/...' : 'https://...'"
                            autofocus
                        />
                        <a
                            v-if="value && !localError"
                            :href="value"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn btn-secondary shrink-0"
                            title="Abrir el link en otra pestaña"
                        >
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">open_in_new</span>
                            Abrir
                        </a>
                    </div>

                    <p v-if="localError || errors.value" class="field-error">{{ localError || errors.value?.[0] }}</p>
                </div>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="checklist-status" class="field-label">Avance</label>
                        <select id="checklist-status" v-model="status" class="field">
                            <option value="">Automático (completado si hay valor)</option>
                            <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="errors.status" class="field-error">{{ errors.status[0] }}</p>
                    </div>
                    <div v-if="item.checked_at" class="text-[12px] text-on-surface-variant sm:pt-6">
                        Última actualización: {{ formatDate(item.checked_at) }}
                        <span v-if="item.checked_by">por {{ item.checked_by }}</span>
                    </div>
                </div>

                <div>
                    <label for="checklist-notes" class="field-label">Notas (opcional)</label>
                    <textarea id="checklist-notes" v-model="notes" rows="2" maxlength="2000" class="field resize-y"></textarea>
                </div>
            </template>
        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">{{ item?.is_automatic ? 'Cerrar' : 'Cancelar' }}</button>
            <button v-if="item && !item.is_automatic" type="submit" form="checklist-value-form" class="btn btn-primary" :disabled="saving || !!localError">
                <span v-if="saving" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                Guardar
            </button>
        </template>
    </AppModal>
</template>
