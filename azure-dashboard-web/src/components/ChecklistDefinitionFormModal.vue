<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import {
    checklistService,
    type ChecklistDefinition,
    type ChecklistDefinitionInput,
    type ChecklistSource,
    type DataType,
    type Option,
} from '@/services/checklistService';
import type { WorkItemType } from '@/services/workItemService';
import { errorMessage, validationErrors } from '@/services/apiClient';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    open: boolean;
    definition: ChecklistDefinition | null;
    types: WorkItemType[];
    dataTypes: Option<DataType>[];
    sources: Option<ChecklistSource>[];
    defaultState?: string;
}>();

const emit = defineEmits<{
    close: [];
    saved: [definition: ChecklistDefinition];
}>();

const toast = useToast();
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const newOption = ref('');

const empty = (): ChecklistDefinitionInput => ({
    label: '',
    description: '',
    data_type: 'text',
    options: [],
    work_item_state: props.defaultState ?? '',
    blocking: false,
    is_required: true,
    applies_to_type: null,
    source: 'manual',
    sort_order: 0,
});

const form = ref<ChecklistDefinitionInput>(empty());

watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    newOption.value = '';
    form.value = props.definition
        ? {
            label: props.definition.label,
            code: props.definition.code,
            description: props.definition.description ?? '',
            data_type: props.definition.data_type,
            options: [...props.definition.options],
            work_item_state: props.definition.work_item_state,
            blocking: props.definition.blocking,
            is_required: props.definition.is_required,
            applies_to_type: props.definition.applies_to_type,
            source: props.definition.source,
            sort_order: props.definition.sort_order,
        }
        : empty();
});

// Estados disponibles: los del tipo elegido, o la unión de todos si aplica a todos los tipos
const availableStates = computed(() => {
    const types = form.value.applies_to_type
        ? props.types.filter((t) => t.name === form.value.applies_to_type)
        : props.types;
    const seen = new Map<string, string>();
    for (const type of types) {
        for (const state of type.states) {
            if (!seen.has(state.name)) seen.set(state.name, state.category);
        }
    }
    return [...seen.keys()];
});

watch(() => form.value.applies_to_type, () => {
    if (form.value.work_item_state && !availableStates.value.includes(form.value.work_item_state)) {
        form.value.work_item_state = '';
    }
});

const hasValues = computed(() => (props.definition?.values_count ?? 0) > 0);

const addOption = () => {
    const value = newOption.value.trim();
    if (value && !form.value.options.includes(value)) form.value.options.push(value);
    newOption.value = '';
};

const removeOption = (index: number) => form.value.options.splice(index, 1);

const save = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const payload = { ...form.value, description: form.value.description || null };
        const saved = props.definition
            ? await checklistService.updateDefinition(props.definition.id, payload)
            : await checklistService.createDefinition(payload);
        toast.success(props.definition ? 'Item actualizado' : 'Item creado');
        emit('saved', saved);
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
    <AppModal :open="open" :title="definition ? 'Editar item de alistamiento' : 'Nuevo item de alistamiento'" size="lg" @close="emit('close')">
        <form id="definition-form" class="grid sm:grid-cols-2 gap-4" @submit.prevent="save">
            <div class="sm:col-span-2">
                <label for="def-label" class="field-label">Nombre *</label>
                <input id="def-label" v-model="form.label" type="text" maxlength="150" class="field" required autofocus />
                <p v-if="errors.label" class="field-error">{{ errors.label[0] }}</p>
            </div>

            <div class="sm:col-span-2">
                <label for="def-description" class="field-label">Descripción / ayuda</label>
                <textarea id="def-description" v-model="form.description" rows="2" maxlength="2000" class="field resize-y" placeholder="Qué se espera que registre el usuario"></textarea>
            </div>

            <div>
                <label for="def-type" class="field-label">Tipo de dato *</label>
                <select id="def-type" v-model="form.data_type" class="field" :disabled="hasValues">
                    <option v-for="type in dataTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
                <p v-if="hasValues" class="text-[11px] text-on-surface-variant mt-1">No se puede cambiar: ya hay {{ definition?.values_count }} valor(es) registrados.</p>
                <p v-if="errors.data_type" class="field-error">{{ errors.data_type[0] }}</p>
            </div>

            <div>
                <label for="def-source" class="field-label">Origen del valor</label>
                <select id="def-source" v-model="form.source" class="field">
                    <option v-for="source in sources" :key="source.value" :value="source.value">{{ source.label }}</option>
                </select>
            </div>

            <div v-if="form.data_type === 'select'" class="sm:col-span-2">
                <span class="field-label">Opciones *</span>
                <div class="flex flex-wrap gap-1.5 mb-2">
                    <span v-for="(option, index) in form.options" :key="option" class="inline-flex items-center gap-1 bg-surface-variant border border-outline-variant rounded-full pl-2.5 pr-1 py-0.5 text-body-sm text-on-surface">
                        {{ option }}
                        <button type="button" class="hover:text-error rounded-full p-0.5" :aria-label="`Quitar opción ${option}`" @click="removeOption(index)">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">close</span>
                        </button>
                    </span>
                    <span v-if="!form.options.length" class="text-body-sm text-on-surface-variant italic">Sin opciones</span>
                </div>
                <div class="flex gap-2">
                    <input v-model="newOption" type="text" maxlength="150" class="field" placeholder="Nueva opción" aria-label="Nueva opción" @keydown.enter.prevent="addOption" />
                    <button type="button" class="btn btn-secondary shrink-0" @click="addOption">Agregar</button>
                </div>
                <p v-if="errors.options" class="field-error">{{ errors.options[0] }}</p>
            </div>

            <div>
                <label for="def-applies" class="field-label">Aplica a</label>
                <select id="def-applies" v-model="form.applies_to_type" class="field">
                    <option :value="null">Todos los tipos de Work Item</option>
                    <option v-for="type in types" :key="type.name" :value="type.name">{{ type.name }}</option>
                </select>
                <p v-if="errors.applies_to_type" class="field-error">{{ errors.applies_to_type[0] }}</p>
            </div>

            <div>
                <label for="def-state" class="field-label">Estado *</label>
                <select id="def-state" v-model="form.work_item_state" class="field" required>
                    <option value="" disabled>Selecciona el estado...</option>
                    <option v-for="state in availableStates" :key="state" :value="state">{{ state }}</option>
                </select>
                <p class="text-[11px] text-on-surface-variant mt-1">Etapa del Work Item en la que se debe cumplir.</p>
                <p v-if="errors.work_item_state" class="field-error">{{ errors.work_item_state[0] }}</p>
            </div>

            <label class="flex items-start gap-2.5 cursor-pointer">
                <input v-model="form.blocking" type="checkbox" class="mt-1 accent-[var(--color-primary-container)]" />
                <span>
                    <span class="block text-body-sm text-on-surface">Bloqueante</span>
                    <span class="block text-[11px] text-on-surface-variant">Se advierte al avanzar de estado si está pendiente, y se exige para "Listo para producción".</span>
                </span>
            </label>

            <label class="flex items-start gap-2.5 cursor-pointer">
                <input v-model="form.is_required" type="checkbox" class="mt-1 accent-[var(--color-primary-container)]" />
                <span>
                    <span class="block text-body-sm text-on-surface">Requerido</span>
                    <span class="block text-[11px] text-on-surface-variant">Si no se marca, el item es opcional.</span>
                </span>
            </label>
        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">Cancelar</button>
            <button type="submit" form="definition-form" class="btn btn-primary" :disabled="saving">
                <span v-if="saving" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                Guardar
            </button>
        </template>
    </AppModal>
</template>
