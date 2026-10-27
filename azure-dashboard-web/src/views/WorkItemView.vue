<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { workItemService, type LinkedPullRequest, type WorkItem, type WorkItemState } from '@/services/workItemService';
import { agentService, type AgentApproval } from '@/services/agentService';
import { errorMessage } from '@/services/apiClient';
import type { ChecklistItem, WorkItemChecklist as Checklist } from '@/services/checklistService';
import StateBadge from '@/components/StateBadge.vue';
import WorkItemChecklist from '@/components/WorkItemChecklist.vue';
import AgentCommentReviewModal from '@/components/AgentCommentReviewModal.vue';
import ConfirmModal from '@/components/ConfirmModal.vue';
import { formatDate, initials, typeColor, typeIcon } from '@/composables/useWorkItemStyles';
import { sanitizeHtml } from '@/composables/useSanitize';
import { useToast } from '@/composables/useToast';
import { useSession } from '@/composables/useSession';

const props = defineProps<{
    id: string | number;
}>();

const router = useRouter();
const toast = useToast();
const { currentUser, refreshPendingApprovals } = useSession();

const workItemData = ref<WorkItem | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const workItemId = computed(() => Number(props.id));

const goBack = () => {
    // Volver conserva los filtros del dashboard
    if (window.history.state?.back) router.back();
    else router.push({ name: 'dashboard' });
};

const loadDetails = async () => {
    if (isNaN(workItemId.value)) {
        error.value = 'ID de Work Item inválido';
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        workItemData.value = await workItemService.getWorkItemDetails(workItemId.value);
        loadStates(workItemData.value.type);
    } catch (e) {
        error.value = errorMessage(e, 'Error al cargar los detalles del Work Item');
    } finally {
        loading.value = false;
    }
};

watch(workItemId, () => {
    workItemData.value = null;
    loadDetails();
}, { immediate: true });

// Solo el responsable del WI puede modificarlo; para el resto la vista es de solo lectura
const canEdit = computed(() => !!workItemData.value?.permissions?.can_edit);

const commentsCount = computed(() =>
    (workItemData.value?.comments?.length || 0) + (workItemData.value?.history?.length || 0));

// ---------------------------------------------------------------------------
// Pull Requests asociados
// ---------------------------------------------------------------------------
const pullRequestStatus = (pr: LinkedPullRequest): { label: string; classes: string } => {
    if (pr.isDraft && pr.status === 'active') return { label: 'Borrador', classes: 'bg-surface-variant text-on-surface-variant' };
    switch (pr.status) {
        case 'active': return { label: 'Activo', classes: 'bg-primary-container text-on-primary-container' };
        case 'completed': return { label: 'Completado', classes: 'bg-success text-on-success' };
        case 'abandoned': return { label: 'Abandonado', classes: 'bg-surface-variant text-on-surface-variant line-through' };
        default: return { label: pr.status || 'Desconocido', classes: 'bg-surface-variant text-on-surface-variant' };
    }
};

// ---------------------------------------------------------------------------
// Comentarios
// ---------------------------------------------------------------------------
const newComment = ref('');
const savingComment = ref(false);

const addComment = async () => {
    const text = newComment.value.trim();
    if (!text || !workItemData.value) return;
    savingComment.value = true;
    try {
        const comment = await workItemService.addComment(workItemData.value.id, text);
        workItemData.value.comments = [comment, ...(workItemData.value.comments ?? [])];
        newComment.value = '';
        toast.success('Comentario publicado en Azure DevOps');
    } catch (e) {
        toast.error(errorMessage(e, 'No se pudo publicar el comentario'));
    } finally {
        savingComment.value = false;
    }
};

// ---------------------------------------------------------------------------
// Estado
// ---------------------------------------------------------------------------
const states = ref<WorkItemState[]>([]);
const savingState = ref(false);
const checklist = ref<Checklist | null>(null);
const pendingStateChange = ref<{ state: string; blocking: ChecklistItem[]; final: boolean } | null>(null);

const loadStates = async (type: string) => {
    try {
        states.value = await workItemService.getStates(type);
    } catch {
        states.value = [];
    }
};

const currentCategory = computed(() =>
    states.value.find((s) => s.name === workItemData.value?.state)?.category);

const stateOptions = computed(() => {
    const names = states.value.map((s) => s.name);
    // Si el estado actual no está en la lista (p. ej. sin conexión), se muestra igual
    const current = workItemData.value?.state;
    return current && !names.includes(current) ? [current, ...names] : names;
});

// Items bloqueantes pendientes de los estados anteriores al estado destino
const pendingBlockingFor = (target: string): ChecklistItem[] => {
    if (!checklist.value || !workItemData.value) return [];
    const order = states.value.map((s) => s.name);
    const targetIndex = order.indexOf(target);
    const currentIndex = order.indexOf(workItemData.value.state);
    const targetCategory = states.value[targetIndex]?.category;
    if (targetIndex <= currentIndex || targetCategory === 'Removed') return [];
    return checklist.value.groups
        .filter((g) => g.order !== null && g.order < targetIndex)
        .flatMap((g) => g.items)
        .filter((i) => i.blocking && !i.is_deleted && i.status !== 'done' && i.status !== 'skipped');
};

const onStateSelected = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const target = select.value;
    // El select vuelve al estado real hasta que Azure confirme
    select.value = workItemData.value?.state ?? '';
    if (!workItemData.value || target === workItemData.value.state) return;

    const category = states.value.find((s) => s.name === target)?.category;
    const blocking = pendingBlockingFor(target);
    const final = category === 'Completed' || category === 'Removed';

    if (blocking.length || final) {
        pendingStateChange.value = { state: target, blocking, final };
    } else {
        changeState(target);
    }
};

const changeState = async (state: string) => {
    if (!workItemData.value) return;
    const previous = workItemData.value.state;
    savingState.value = true;
    workItemData.value.state = state; // optimista
    try {
        const result = await workItemService.updateState(workItemData.value.id, workItemData.value.type, state);
        workItemData.value.state = result.state;
        if (result.changed_date) workItemData.value.changed_date = result.changed_date;
        toast.success(`Estado cambiado a "${result.state}"`);
        pendingStateChange.value = null;
    } catch (e) {
        workItemData.value.state = previous;
        toast.error(errorMessage(e, 'No se pudo cambiar el estado'));
    } finally {
        savingState.value = false;
    }
};

// ---------------------------------------------------------------------------
// Agente IA
// ---------------------------------------------------------------------------
const runningAgent = ref(false);
const approval = ref<AgentApproval | null>(null);

const runAgent = async () => {
    if (!workItemData.value) return;
    runningAgent.value = true;
    try {
        approval.value = await agentService.propose(workItemData.value.id);
        refreshPendingApprovals();
    } catch (e) {
        toast.error(errorMessage(e, 'No se pudo ejecutar el agente'));
    } finally {
        runningAgent.value = false;
    }
};

const onApprovalResolved = (resolved: AgentApproval) => {
    if (resolved.status === 'Publicado') {
        loadDetails();
    } else if (resolved.status === 'Pendiente') {
        approval.value = resolved;
    }
};
</script>

<template>
    <div class="flex-1 flex flex-col bg-background lg:h-[calc(100vh-3.5rem)] lg:overflow-hidden">
        <!-- Breadcrumb Header -->
        <div class="bg-surface-container-high border-b border-outline-variant px-4 md:px-6 py-3 shrink-0">
            <nav class="flex items-center gap-1.5 font-label-mono text-label-mono text-on-surface-variant" aria-label="Ruta de navegación">
                <button
                    type="button"
                    class="flex items-center gap-1.5 text-primary hover:text-on-surface transition-colors"
                    title="Volver al dashboard"
                    @click="goBack"
                >
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_back</span>
                    <span>Dashboard</span>
                </button>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span class="text-on-surface font-semibold" aria-current="page">#{{ id }}</span>
            </nav>
        </div>

        <!-- Title Section -->
        <div v-if="workItemData" class="bg-surface-container-high border-b border-outline-variant px-4 md:px-6 py-4 shrink-0">
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <h1 class="text-on-surface font-headline-lg text-headline-lg font-bold">
                        {{ workItemData.title }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-2.5 mt-3">
                        <StateBadge :state="workItemData.state" :category="currentCategory" />
                        <span class="flex items-center gap-1 text-on-surface-variant text-xs">
                            <span class="material-symbols-outlined text-[14px]" :class="typeColor(workItemData.type)" aria-hidden="true">{{ typeIcon(workItemData.type) }}</span>
                            {{ workItemData.type }}
                        </span>
                    </div>
                </div>
                <div v-if="canEdit" class="flex flex-col sm:flex-row gap-3 shrink-0">
                    <div>
                        <label for="work-item-state" class="sr-only">Estado del Work Item</label>
                        <div class="relative">
                            <select
                                id="work-item-state"
                                class="w-full sm:w-44 bg-background border border-outline-variant rounded-lg p-2.5 text-on-surface text-body-sm appearance-none input-glow cursor-pointer pr-8 disabled:opacity-60"
                                :value="workItemData.state"
                                :disabled="savingState || !states.length"
                                @change="onStateSelected"
                            >
                                <option v-for="state in stateOptions" :key="state" :value="state">{{ state }}</option>
                            </select>
                            <span
                                class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none text-[18px]"
                                :class="{ 'animate-spin': savingState }"
                                aria-hidden="true"
                            >{{ savingState ? 'progress_activity' : 'expand_more' }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="runningAgent"
                        title="Revisa el PR asociado y los commits de hoy para proponer el comentario del día"
                        @click="runAgent"
                    >
                        <span class="material-symbols-outlined text-[18px]" :class="{ 'animate-spin-slow': runningAgent }" aria-hidden="true">{{ runningAgent ? 'auto_awesome' : 'smart_toy' }}</span>
                        {{ runningAgent ? 'Revisando PR y commits...' : 'Run Azure Agent' }}
                    </button>
                </div>
            </div>
            <p v-if="!canEdit" class="mt-3 flex items-center gap-2 text-body-sm text-on-surface-variant bg-surface-container border border-outline-variant rounded-lg px-3 py-2">
                <span class="material-symbols-outlined text-[18px] text-warning" aria-hidden="true">visibility</span>
                <span>
                    <strong class="text-on-surface">Solo lectura</strong> — asignado a {{ workItemData.assigned_to }}.
                    Solo puedes modificar los work items asignados a ti.
                </span>
            </p>
        </div>

        <div v-if="loading && !workItemData" class="flex-1 flex items-center justify-center text-on-surface-variant p-10">
            <span class="material-symbols-outlined animate-spin text-4xl" aria-hidden="true">progress_activity</span>
        </div>

        <div v-else-if="error && !workItemData" class="flex-1 flex items-center justify-center p-10">
            <div class="text-center">
                <p class="text-error mb-4">{{ error }}</p>
                <button type="button" class="btn btn-primary" @click="goBack">Volver al dashboard</button>
            </div>
        </div>

        <!-- 2-Column Layout -->
        <div v-else-if="workItemData" class="flex-1 flex flex-col lg:flex-row lg:overflow-hidden">
            <!-- Left Column: Details & Content -->
            <div class="flex-1 lg:overflow-y-auto custom-scrollbar p-4 md:p-6 flex flex-col gap-6 pb-8">
                <!-- Description Section -->
                <section class="bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                    <div class="bg-surface-container-high border-b border-outline-variant px-4 py-3">
                        <h2 class="font-headline-sm text-headline-sm text-on-surface">Descripción</h2>
                    </div>
                    <div class="p-4 text-body-md text-on-surface min-h-[120px]">
                        <div
                            v-if="workItemData.description && workItemData.description.trim()"
                            class="rich-text"
                            v-html="sanitizeHtml(workItemData.description)"
                        ></div>
                        <p v-else class="text-on-surface-variant italic">Sin descripción</p>
                    </div>
                </section>

                <!-- Discussion Section -->
                <section class="flex flex-col gap-4">
                    <div class="flex items-center justify-between border-b border-outline-variant pb-2">
                        <h2 class="font-headline-sm text-headline-sm text-on-surface">Discusión / Historial</h2>
                        <span class="bg-surface-variant text-on-surface-variant px-2.5 py-0.5 rounded-full font-label-mono text-[10px]">{{ commentsCount }} elementos</span>
                    </div>

                    <!-- Add Comment Area -->
                    <form v-if="canEdit" class="flex gap-3" @submit.prevent="addComment">
                        <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container text-xs font-bold shrink-0" aria-hidden="true">
                            {{ initials(currentUser?.displayName) }}
                        </div>
                        <div class="flex-1 bg-background border border-outline-variant rounded-xl input-glow focus-within:bg-surface-container transition-colors">
                            <label for="new-comment" class="sr-only">Agregar un comentario</label>
                            <textarea
                                id="new-comment"
                                v-model="newComment"
                                class="w-full bg-transparent border-none text-on-surface text-body-sm p-3 min-h-[80px] focus:outline-none resize-y custom-scrollbar"
                                placeholder="Agregar un comentario..."
                                maxlength="10000"
                                :disabled="savingComment"
                                @keydown.ctrl.enter.prevent="addComment"
                                @keydown.meta.enter.prevent="addComment"
                            ></textarea>
                            <div class="flex justify-between items-center gap-2 px-3 py-2 border-t border-outline-variant bg-surface-container-low rounded-b-xl">
                                <span class="text-[11px] text-on-surface-variant">Markdown · Ctrl+Enter para guardar</span>
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="savingComment || !newComment.trim()">
                                    <span v-if="savingComment" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                                    Guardar
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Comment Feed -->
                    <div class="flex flex-col gap-4">
                        <!-- Comments (más recientes primero) -->
                        <article v-for="comment in workItemData.comments" :key="comment.id" class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center text-on-secondary-container text-xs font-bold shrink-0 border border-outline-variant" aria-hidden="true">
                                {{ initials(comment.createdBy?.displayName) }}
                            </div>
                            <div class="flex-1 min-w-0 bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                                <div class="px-4 py-2.5 border-b border-outline-variant flex flex-wrap items-center gap-x-2 bg-surface-container-high">
                                    <span class="text-body-sm font-semibold text-on-surface">{{ comment.createdBy?.displayName || 'Usuario' }}</span>
                                    <time class="text-on-surface-variant text-[11px]" :datetime="comment.createdDate">{{ formatDate(comment.createdDate) }}</time>
                                </div>
                                <div class="p-4 text-body-sm text-on-surface rich-text" v-html="sanitizeHtml(comment.text)"></div>
                            </div>
                        </article>

                        <!-- History / System Actions -->
                        <div v-for="update in workItemData.history" :key="update.id" class="flex items-start gap-2 pl-11 py-1">
                            <span class="material-symbols-outlined text-[14px] text-on-surface-variant mt-0.5" aria-hidden="true">change_circle</span>
                            <div class="text-body-sm text-on-surface-variant">
                                <strong class="text-on-surface">{{ update.revisedBy?.displayName || 'Sistema' }}</strong>
                                <span class="ml-1">{{ formatDate(update.revisedDate) }}</span>
                                <div class="mt-1 text-xs">
                                    <template v-for="(fieldData, fieldName) in update.fields" :key="fieldName">
                                        <div v-if="fieldName === 'System.State' && fieldData.oldValue && fieldData.newValue" class="mt-0.5">
                                            cambió estado de <span class="line-through">{{ fieldData.oldValue }}</span> a <strong class="text-primary">{{ fieldData.newValue }}</strong>
                                        </div>
                                        <div v-else-if="fieldName === 'System.AssignedTo' && fieldData.newValue" class="mt-0.5">
                                            asignó a <strong class="text-on-surface">{{ fieldData.newValue.displayName || fieldData.newValue }}</strong>
                                            <span v-if="fieldData.oldValue"> (anterior: {{ fieldData.oldValue.displayName || fieldData.oldValue }})</span>
                                        </div>
                                        <div v-else-if="fieldName === 'System.Title' && fieldData.newValue" class="mt-0.5">
                                            actualizó el título
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <p v-if="!commentsCount" class="text-on-surface-variant italic text-sm pl-2">
                            Sin comentarios ni historial
                        </p>
                    </div>
                </section>
            </div>

            <!-- Right Column: Metadata / Sidebar -->
            <aside class="w-full lg:w-96 border-t lg:border-t-0 lg:border-l border-outline-variant bg-surface-container-low lg:overflow-y-auto custom-scrollbar flex flex-col shrink-0">
                <!-- Work Item Info -->
                <div class="p-4 border-b border-outline-variant bg-surface-container-high">
                    <h3 class="font-label-caps text-label-caps uppercase text-primary mb-3 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                        Información
                    </h3>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div class="col-span-2 flex items-center gap-2.5 p-2 bg-surface-container rounded-lg mb-1">
                            <div class="w-6 h-6 rounded-full bg-primary-container flex items-center justify-center text-[10px] font-bold text-on-primary-container" aria-hidden="true">
                                {{ initials(workItemData.assigned_to) }}
                            </div>
                            <div class="min-w-0">
                                <dt class="text-on-surface-variant text-[10px] uppercase">Asignado a</dt>
                                <dd class="text-body-sm text-on-surface truncate">{{ workItemData.assigned_to }}</dd>
                            </div>
                        </div>
                        <div v-if="workItemData.created_by">
                            <dt class="text-on-surface-variant">Creado por</dt>
                            <dd class="text-on-surface truncate">{{ workItemData.created_by.displayName }}</dd>
                        </div>
                        <div v-if="workItemData.created_date">
                            <dt class="text-on-surface-variant">Creado</dt>
                            <dd class="text-on-surface">{{ formatDate(workItemData.created_date) }}</dd>
                        </div>
                        <div v-if="workItemData.changed_by">
                            <dt class="text-on-surface-variant">Actualizado por</dt>
                            <dd class="text-on-surface truncate">{{ workItemData.changed_by.displayName }}</dd>
                        </div>
                        <div v-if="workItemData.changed_date">
                            <dt class="text-on-surface-variant">Actualizado</dt>
                            <dd class="text-on-surface">{{ formatDate(workItemData.changed_date) }}</dd>
                        </div>
                        <div v-if="workItemData.area_path" class="col-span-2">
                            <dt class="text-on-surface-variant">Area Path</dt>
                            <dd class="text-on-surface truncate" :title="workItemData.area_path">{{ workItemData.area_path }}</dd>
                        </div>
                        <div v-if="workItemData.iteration_path" class="col-span-2">
                            <dt class="text-on-surface-variant">Iteration Path</dt>
                            <dd class="text-on-surface truncate" :title="workItemData.iteration_path">{{ workItemData.iteration_path }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Pull Requests asociados -->
                <div class="p-4 border-b border-outline-variant">
                    <h3 class="font-label-caps text-label-caps uppercase text-primary mb-3 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">merge</span>
                        Pull Requests
                        <span class="ml-auto bg-surface-variant text-on-surface-variant px-2 py-0.5 rounded-full font-label-mono text-[10px] normal-case">{{ workItemData.pull_requests?.length ?? 0 }}</span>
                    </h3>
                    <ul v-if="workItemData.pull_requests?.length" class="flex flex-col gap-2">
                        <li v-for="pr in workItemData.pull_requests" :key="`${pr.repositoryId}-${pr.id}`" class="p-2.5 bg-surface-container rounded-lg border border-outline-variant text-xs">
                            <template v-if="pr.error">
                                <p class="font-semibold text-on-surface">PR !{{ pr.id }}</p>
                                <p class="text-error mt-0.5 break-words">No se pudo consultar en Azure DevOps</p>
                            </template>
                            <template v-else>
                                <div class="flex items-start justify-between gap-2">
                                    <a
                                        :href="pr.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-body-sm text-primary hover:underline font-semibold min-w-0 break-words"
                                    >PR !{{ pr.id }} · {{ pr.title }}</a>
                                    <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-semibold" :class="pullRequestStatus(pr).classes">{{ pullRequestStatus(pr).label }}</span>
                                </div>
                                <p class="mt-1.5 flex items-center gap-1 font-label-mono text-[11px] text-on-surface-variant min-w-0">
                                    <span class="truncate" :title="pr.sourceBranch">{{ pr.sourceBranch }}</span>
                                    <span class="material-symbols-outlined text-[14px] shrink-0" aria-label="hacia">arrow_forward</span>
                                    <span class="truncate" :title="pr.targetBranch">{{ pr.targetBranch }}</span>
                                </p>
                                <p class="mt-1 text-on-surface-variant truncate">
                                    {{ pr.repository }}<template v-if="pr.createdBy"> · {{ pr.createdBy }}</template><template v-if="pr.creationDate"> · {{ formatDate(pr.creationDate) }}</template>
                                </p>
                            </template>
                        </li>
                    </ul>
                    <p v-else class="text-on-surface-variant italic text-xs">Sin Pull Requests asociados</p>
                </div>

                <!-- Alistamiento -->
                <WorkItemChecklist :work-item-id="workItemData.id" :state="workItemData.state" :readonly="!canEdit" @loaded="checklist = $event" />
            </aside>
        </div>

        <!-- Confirmación de cambio de estado -->
        <ConfirmModal
            :open="!!pendingStateChange"
            :title="`Cambiar estado a &quot;${pendingStateChange?.state}&quot;`"
            :confirm-label="pendingStateChange?.blocking.length ? 'Cambiar de todas formas' : 'Cambiar estado'"
            :danger="!!pendingStateChange?.blocking.length"
            :loading="savingState"
            @confirm="changeState(pendingStateChange!.state)"
            @cancel="pendingStateChange = null"
        >
            <template v-if="pendingStateChange?.blocking.length">
                <p class="flex items-center gap-1.5 text-warning font-semibold">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>
                    Hay items bloqueantes del alistamiento pendientes:
                </p>
                <ul class="list-disc pl-5 text-on-surface">
                    <li v-for="item in pendingStateChange.blocking" :key="item.definition_id">
                        {{ item.label }} <span class="text-on-surface-variant">({{ item.work_item_state }})</span>
                    </li>
                </ul>
            </template>
            <p>El cambio se aplicará directamente en Azure DevOps.</p>
        </ConfirmModal>

        <AgentCommentReviewModal
            :open="!!approval"
            :approval="approval"
            @close="approval = null"
            @resolved="onApprovalResolved"
        />
    </div>
</template>
