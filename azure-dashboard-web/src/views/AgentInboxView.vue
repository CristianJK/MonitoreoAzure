<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AgentCommentReviewModal from '@/components/AgentCommentReviewModal.vue';
import { agentService, type AgentApproval, type AgentRun } from '@/services/agentService';
import { errorMessage } from '@/services/apiClient';
import { formatDate } from '@/composables/useWorkItemStyles';
import { useSession } from '@/composables/useSession';

const route = useRoute();
const router = useRouter();
const { refreshPendingApprovals } = useSession();

const approvals = ref<AgentApproval[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const tab = ref<'proposal' | 'manual'>('proposal');
const selected = ref<AgentApproval | null>(null);

// Progreso del lote ("Daily Comment Automation")
const batchId = computed(() => (route.query.batch as string | undefined) || null);
const runs = ref<AgentRun[]>([]);
const batchStartedAt = ref(Date.now());
let poll: ReturnType<typeof setInterval> | undefined;

let tabChosen = false;

const loadApprovals = async () => {
    try {
        approvals.value = await agentService.getApprovals('Pendiente');
        error.value = null;
        // La primera vez se abre la pestaña que tiene pendientes
        if (!tabChosen && approvals.value.length) {
            tab.value = approvals.value.some((a) => a.has_commits) ? 'proposal' : 'manual';
            tabChosen = true;
        }
    } catch (e) {
        error.value = errorMessage(e, 'No se pudo cargar la bandeja');
    } finally {
        loading.value = false;
    }
};

const batchDone = computed(() => runs.value.length > 0 && runs.value.every((r) => r.status !== 'queued'));
const processed = computed(() => runs.value.filter((r) => r.status !== 'queued').length);
const failedRuns = computed(() => runs.value.filter((r) => r.status === 'error'));
// Si después de 20 s nada avanzó, probablemente no hay worker de colas corriendo
const looksStuck = computed(() => !batchDone.value && processed.value === 0 && Date.now() - batchStartedAt.value > 20_000);

const loadRuns = async () => {
    if (!batchId.value) return;
    try {
        runs.value = await agentService.getRuns(batchId.value);
        if (batchDone.value) {
            stopPolling();
            refreshPendingApprovals();
        }
        await loadApprovals();
    } catch {
        // se reintenta en el siguiente ciclo
    }
};

const stopPolling = () => {
    clearInterval(poll);
    poll = undefined;
};

watch(batchId, (id) => {
    stopPolling();
    runs.value = [];
    if (id) {
        batchStartedAt.value = Date.now();
        loadRuns();
        poll = setInterval(loadRuns, 3000);
    }
}, { immediate: true });

onMounted(loadApprovals);
onBeforeUnmount(stopPolling);

const selectTab = (key: 'proposal' | 'manual') => {
    tab.value = key;
    tabChosen = true;
};

const withCommits = computed(() => approvals.value.filter((a) => a.has_commits));
const withoutCommits = computed(() => approvals.value.filter((a) => !a.has_commits));
const visible = computed(() => (tab.value === 'proposal' ? withCommits.value : withoutCommits.value));

const onResolved = (approval: AgentApproval) => {
    if (approval.status === 'Pendiente') {
        const index = approvals.value.findIndex((a) => a.id === approval.id);
        if (index >= 0) approvals.value[index] = approval;
        selected.value = approval;
    } else {
        approvals.value = approvals.value.filter((a) => a.id !== approval.id);
    }
};

const origin = (approval: AgentApproval) => ({
    manual: 'Manual',
    batch: 'Lote',
    scheduled: 'Programado',
}[approval.requested_by] ?? approval.requested_by);

const clearBatch = () => router.replace({ query: {} });
</script>

<template>
    <main class="flex-1 p-4 md:p-6 overflow-y-auto bg-background">
        <div class="max-w-5xl mx-auto space-y-6">
            <div>
                <h1 class="text-headline-lg font-headline-lg text-on-surface">Comentarios por revisar</h1>
                <p class="text-body-sm text-on-surface-variant mt-1">
                    Propuestas del agente (ejecución manual, en lote o programada). Nada se publica en Azure DevOps sin tu revisión.
                </p>
            </div>

            <!-- Progreso del lote -->
            <section v-if="batchId" class="bg-surface-container border border-outline-variant rounded-xl p-4 space-y-3" aria-live="polite">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 text-headline-sm font-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-primary" :class="{ 'animate-spin-slow': !batchDone }" aria-hidden="true">auto_awesome</span>
                        {{ batchDone ? 'Análisis terminado' : 'Analizando work items...' }}
                    </h2>
                    <button v-if="batchDone" type="button" class="btn btn-ghost btn-sm" @click="clearBatch">Ocultar</button>
                </div>
                <div class="flex justify-between text-[12px] text-on-surface-variant">
                    <span>{{ processed }} de {{ runs.length }} procesados</span>
                    <span v-if="batchDone">
                        {{ runs.filter((r) => r.status === 'proposal').length }} con commits ·
                        {{ runs.filter((r) => r.status === 'manual_required').length }} sin commits
                        <template v-if="failedRuns.length"> · {{ failedRuns.length }} con error</template>
                    </span>
                </div>
                <div class="h-2 rounded-full bg-surface-container-lowest overflow-hidden">
                    <div class="h-full bg-primary-container transition-all" :style="{ width: runs.length ? (processed / runs.length * 100) + '%' : '0%' }"></div>
                </div>
                <p v-if="looksStuck" class="flex items-start gap-2 text-[12px] text-warning">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>
                    No hay avance. Verifica que el worker de colas esté corriendo:
                    <code class="font-label-mono">sail artisan queue:work</code>
                </p>
                <ul v-if="failedRuns.length" class="text-[12px] text-error space-y-0.5">
                    <li v-for="run in failedRuns" :key="run.id">#{{ run.azure_work_item_id }}: {{ run.error }}</li>
                </ul>
            </section>

            <!-- Pestañas -->
            <div class="flex gap-1 border-b border-outline-variant" role="tablist">
                <button
                    v-for="t in [{ key: 'proposal', label: 'Con propuesta', count: withCommits.length, icon: 'smart_toy' }, { key: 'manual', label: 'Sin commits', count: withoutCommits.length, icon: 'edit_note' }] as const"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    class="flex items-center gap-2 px-4 py-2.5 text-body-sm border-b-2 -mb-px transition-colors"
                    :class="tab === t.key ? 'border-primary text-primary font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface'"
                    @click="selectTab(t.key)"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ t.icon }}</span>
                    {{ t.label }}
                    <span class="text-[11px] bg-surface-variant px-1.5 py-0.5 rounded-full">{{ t.count }}</span>
                </button>
            </div>

            <div v-if="loading" class="p-8 text-center text-on-surface-variant">Cargando...</div>
            <div v-else-if="error" class="p-6 text-center text-error bg-error/10 border border-error/30 rounded-xl">{{ error }}</div>

            <div v-else-if="!visible.length" class="p-10 text-center bg-surface-container border border-outline-variant rounded-xl">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant" aria-hidden="true">inbox</span>
                <p class="text-on-surface mt-2">
                    {{ tab === 'proposal' ? 'No hay propuestas pendientes.' : 'No hay Work Items sin commits pendientes de comentario.' }}
                </p>
            </div>

            <ul v-else class="space-y-2">
                <li v-for="approval in visible" :key="approval.id">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-surface-container border border-outline-variant rounded-xl p-4 hover:border-primary/50 transition-colors">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 text-body-sm">
                                <router-link :to="{ name: 'work-item', params: { id: approval.work_item_id } }" class="font-label-mono text-primary hover:underline">#{{ approval.work_item_id }}</router-link>
                                <span class="font-semibold text-on-surface truncate">{{ approval.work_item?.title }}</span>
                            </div>
                            <p class="text-[12px] text-on-surface-variant mt-1 flex flex-wrap gap-x-3">
                                <span>{{ origin(approval) }} · {{ formatDate(approval.created_at) }}</span>
                                <span v-if="approval.has_commits">{{ approval.context?.commits.length }} commits hoy</span>
                                <span v-else-if="!approval.context?.pull_requests.length && !approval.context?.branches.length">Sin PR ni rama asociados</span>
                                <span v-if="approval.fallback_reason" class="text-warning">Generado sin IA</span>
                            </p>
                        </div>
                        <button type="button" class="btn shrink-0" :class="approval.has_commits ? 'btn-primary' : 'btn-secondary'" @click="selected = approval">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ approval.has_commits ? 'rate_review' : 'edit' }}</span>
                            {{ approval.has_commits ? 'Revisar' : 'Escribir comentario' }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <AgentCommentReviewModal
            :open="!!selected"
            :approval="selected"
            @close="selected = null"
            @resolved="onResolved"
        />
    </main>
</template>
