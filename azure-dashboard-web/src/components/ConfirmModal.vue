<script setup lang="ts">
import AppModal from './AppModal.vue';

withDefaults(defineProps<{
    open: boolean;
    title: string;
    confirmLabel?: string;
    danger?: boolean;
    loading?: boolean;
}>(), {
    confirmLabel: 'Confirmar',
    danger: false,
    loading: false,
});

defineEmits<{
    confirm: [];
    cancel: [];
}>();
</script>

<template>
    <AppModal :open="open" :title="title" size="sm" @close="$emit('cancel')">
        <div class="text-body-sm text-on-surface-variant space-y-2">
            <slot />
        </div>
        <template #footer>
            <!-- En acciones peligrosas el foco inicial queda en Cancelar -->
            <button type="button" class="btn btn-secondary" :autofocus="danger" @click="$emit('cancel')">Cancelar</button>
            <button
                type="button"
                class="btn"
                :class="danger ? 'btn-danger' : 'btn-primary'"
                :disabled="loading"
                :autofocus="!danger"
                @click="$emit('confirm')"
            >
                <span v-if="loading" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                {{ confirmLabel }}
            </button>
        </template>
    </AppModal>
</template>
