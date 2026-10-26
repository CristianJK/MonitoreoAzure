<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { userService, type AzureUser } from '@/services/userService';
import { initials } from '@/composables/useWorkItemStyles';

/**
 * Selector de "Asignado a": por defecto @Me, con búsqueda de otros usuarios del proyecto.
 * value = '@Me' o el uniqueName (correo) del usuario elegido; label = su nombre visible.
 */
const props = defineProps<{
    value: string;
    label?: string;
}>();

const emit = defineEmits<{
    select: [value: string, label: string];
}>();

const inputId = useId();
const listId = useId();
const query = ref('');
const open = ref(false);
const loading = ref(false);
const results = ref<AzureUser[]>([]);
const highlighted = ref(0);
let timer: ReturnType<typeof setTimeout> | undefined;

const isMe = computed(() => props.value.toLowerCase() === '@me');
const displayValue = computed(() => (isMe.value ? 'Yo (@Me)' : props.label || props.value));

const search = async () => {
    loading.value = true;
    try {
        results.value = await userService.search(query.value);
        highlighted.value = 0;
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
    }
};

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 300);
});

const openList = () => {
    open.value = true;
    if (!results.value.length) search();
};

const select = (user: AzureUser | null) => {
    emit('select', user ? user.uniqueName : '@Me', user ? user.displayName : '');
    query.value = '';
    open.value = false;
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        open.value = true;
        highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1);
        event.preventDefault();
    } else if (event.key === 'ArrowUp') {
        highlighted.value = Math.max(highlighted.value - 1, 0);
        event.preventDefault();
    } else if (event.key === 'Enter' && open.value && results.value[highlighted.value]) {
        select(results.value[highlighted.value]);
        event.preventDefault();
    } else if (event.key === 'Escape') {
        open.value = false;
    }
};

const onBlur = () => setTimeout(() => (open.value = false), 150);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <label :for="inputId" class="field-label">Asignado a</label>
        <div class="flex gap-2">
            <div class="relative flex-1">
                <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px] pointer-events-none" aria-hidden="true">person_search</span>
                <input
                    :id="inputId"
                    v-model="query"
                    type="text"
                    role="combobox"
                    autocomplete="off"
                    class="field pl-9"
                    :placeholder="displayValue"
                    :aria-expanded="open"
                    :aria-controls="listId"
                    @focus="openList"
                    @blur="onBlur"
                    @keydown="onKeydown"
                />
            </div>
            <button
                v-if="!isMe"
                type="button"
                class="btn btn-secondary shrink-0"
                title="Volver a mis Work Items"
                @click="select(null)"
            >
                Yo
            </button>
        </div>
        <p class="text-[11px] text-on-surface-variant mt-1 truncate">
            Mostrando: <strong class="text-on-surface">{{ displayValue }}</strong>
        </p>

        <ul
            v-if="open"
            :id="listId"
            role="listbox"
            class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto custom-scrollbar bg-surface-container-high border border-outline-variant rounded-lg shadow-xl py-1"
        >
            <li v-if="loading" class="px-3 py-2 text-body-sm text-on-surface-variant">Buscando...</li>
            <li v-else-if="!results.length" class="px-3 py-2 text-body-sm text-on-surface-variant">Sin resultados</li>
            <li
                v-for="(user, index) in results"
                v-else
                :key="user.uniqueName"
                role="option"
                :aria-selected="index === highlighted"
                class="flex items-center gap-2.5 px-3 py-2 cursor-pointer"
                :class="index === highlighted ? 'bg-surface-variant' : 'hover:bg-surface-variant'"
                @mousedown.prevent="select(user)"
                @mouseenter="highlighted = index"
            >
                <span class="w-7 h-7 rounded-full bg-surface-bright border border-outline-variant flex items-center justify-center text-[11px] font-bold text-on-surface shrink-0">
                    {{ initials(user.displayName) }}
                </span>
                <span class="min-w-0">
                    <span class="block text-body-sm text-on-surface truncate">{{ user.displayName }}</span>
                    <span class="block text-[11px] text-on-surface-variant truncate">{{ user.uniqueName }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
