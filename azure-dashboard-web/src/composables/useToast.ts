import { ref } from 'vue';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    kind: ToastKind;
    message: string;
}

const toasts = ref<Toast[]>([]);
let nextId = 1;

const dismiss = (id: number) => {
    toasts.value = toasts.value.filter((t) => t.id !== id);
};

const push = (kind: ToastKind, message: string, timeout = kind === 'error' ? 7000 : 4000) => {
    const id = nextId++;
    toasts.value.push({ id, kind, message });
    setTimeout(() => dismiss(id), timeout);
};

export const useToast = () => ({
    toasts,
    dismiss,
    success: (message: string) => push('success', message),
    error: (message: string) => push('error', message),
    info: (message: string) => push('info', message),
});
