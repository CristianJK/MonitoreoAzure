<script setup lang="ts">
withDefaults(defineProps<{
    title: string;
    value: number;
    description?: string;
    icon: string;
    accentClass?: string;
}>(), {
    description: '',
    accentClass: 'bg-primary-container',
});
</script>
<template>
    <div class="bg-surface-container p-4 rounded-lg border border-outline-variant relative overflow-hidden">
        <div class="absolute top-0 left-0 w-1 h-full" :class="accentClass"></div>
        <div class="flex justify-between items-start mb-2">
            <span class="font-label-caps text-label-caps text-on-surface-variant uppercase tracking-wider">{{ title }}</span>
            <span class="material-symbols-outlined text-primary-container">{{ icon }}</span>
        </div>
        <div class="font-headline-lg text-headline-lg text-on-surface">{{ value }}</div>
        <div class="flex items-center gap-1 mt-2 text-on-surface-variant font-body-sm text-body-sm">
            <span>{{ description }}</span>
        </div>
    </div>
</template>