<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = withDefaults(defineProps<{
    open: boolean;
    title: string;
    subtitle?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    closable?: boolean;
}>(), {
    subtitle: '',
    size: 'md',
    closable: true,
});

const emit = defineEmits<{
    close: [];
}>();

const titleId = useId();
const panel = ref<HTMLElement | null>(null);
let previouslyFocused: HTMLElement | null = null;

const sizeClass = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
};

const close = () => {
    if (props.closable) emit('close');
};

const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        return;
    }
    // Mantiene el foco dentro del modal
    if (event.key === 'Tab' && panel.value) {
        const focusable = Array.from(panel.value.querySelectorAll<HTMLElement>(focusableSelector));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            last.focus();
            event.preventDefault();
        } else if (!event.shiftKey && document.activeElement === last) {
            first.focus();
            event.preventDefault();
        }
    }
};

watch(() => props.open, async (open) => {
    if (open) {
        previouslyFocused = document.activeElement as HTMLElement | null;
        document.body.style.overflow = 'hidden';
        await nextTick();
        const autofocus = panel.value?.querySelector<HTMLElement>('[autofocus]')
            ?? panel.value?.querySelector<HTMLElement>('input, select, textarea')
            ?? panel.value;
        autofocus?.focus();
    } else {
        document.body.style.overflow = '';
        previouslyFocused?.focus?.();
    }
}, { immediate: true });

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm p-0 sm:p-4"
                @mousedown.self="close"
            >
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    class="bg-surface-container border border-outline-variant w-full rounded-t-xl sm:rounded-xl shadow-2xl flex flex-col max-h-[92vh] outline-none"
                    :class="sizeClass[size]"
                    @keydown="onKeydown"
                >
                    <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-outline-variant shrink-0">
                        <div class="min-w-0">
                            <h2 :id="titleId" class="text-headline-sm font-headline-sm text-on-surface">{{ title }}</h2>
                            <p v-if="subtitle" class="text-body-sm text-on-surface-variant mt-0.5 truncate">{{ subtitle }}</p>
                        </div>
                        <button
                            v-if="closable"
                            type="button"
                            class="text-on-surface-variant hover:text-on-surface hover:bg-surface-variant p-1 rounded-lg transition-colors shrink-0"
                            aria-label="Cerrar"
                            @click="close"
                        >
                            <span class="material-symbols-outlined" aria-hidden="true">close</span>
                        </button>
                    </header>

                    <div class="px-5 py-4 overflow-y-auto custom-scrollbar flex-1">
                        <slot />
                    </div>

                    <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 px-5 py-3 border-t border-outline-variant bg-surface-container-low rounded-b-xl shrink-0">
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
