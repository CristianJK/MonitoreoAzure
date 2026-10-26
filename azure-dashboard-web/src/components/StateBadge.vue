<script setup lang="ts">
import { computed } from 'vue';
import { stateBadgeClasses } from '@/composables/useWorkItemStyles';

const props = defineProps<{
    state: string;
    category?: string | null;
}>();

const classes = computed(() => stateBadgeClasses(props.state, props.category));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border text-xs font-medium whitespace-nowrap" :class="classes.badge">
        <span class="w-1.5 h-1.5 rounded-full" :class="classes.dot" aria-hidden="true"></span>
        {{ state }}
    </span>
</template>
