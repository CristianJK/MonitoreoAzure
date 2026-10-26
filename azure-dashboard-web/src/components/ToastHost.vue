<script setup lang="ts">
import { useToast } from '@/composables/useToast';

const { toasts, dismiss } = useToast();

const icon = { success: 'check_circle', error: 'error', info: 'info' } as const;
const classes = {
    success: 'border-success/40 text-success',
    error: 'border-error/40 text-error',
    info: 'border-primary/40 text-primary',
} as const;
</script>

<template>
    <div class="fixed bottom-4 right-4 left-4 sm:left-auto z-[70] flex flex-col gap-2 sm:w-96" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="flex items-start gap-3 bg-surface-container-high border rounded-lg px-4 py-3 shadow-lg"
                :class="classes[toast.kind]"
                :role="toast.kind === 'error' ? 'alert' : 'status'"
            >
                <span class="material-symbols-outlined text-[20px] shrink-0" aria-hidden="true">{{ icon[toast.kind] }}</span>
                <p class="text-body-sm text-on-surface flex-1">{{ toast.message }}</p>
                <button
                    type="button"
                    class="text-on-surface-variant hover:text-on-surface shrink-0"
                    aria-label="Cerrar notificación"
                    @click="dismiss(toast.id)"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
