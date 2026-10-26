<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import { agentService, type AgentApproval } from '@/services/agentService';
import { errorCode, errorMessage } from '@/services/apiClient';
import { useToast } from '@/composables/useToast';
import { useSession } from '@/composables/useSession';

/**
 * Revisión de la propuesta del agente. Con commits muestra el texto de Copilot
 * para editarlo; sin commits pide escribir el comentario manualmente.
 */
const props = defineProps<{
    open: boolean;
    approval: AgentApproval | null;
}>();

const emit = defineEmits<{
    close: [];
    resolved: [approval: AgentApproval];
}>();

const toast = useToast();
const { refreshPendingApprovals } = useSession();
const text = ref('');
const busy = ref<'publish' | 'regenerate' | 'reject' | null>(null);
const alreadyPublished = ref(false);
const showContext = ref(false);

watch(() => props.approval, (approval) => {
    text.value = approval?.proposal ?? '';
    alreadyPublished.value = false;
    showContext.value = false;
}, { immediate: true });

const context = computed(() => props.approval?.context);
const isManual = computed(() => props.approval ? !props.approval.has_commits : false);
const fileCount = computed(() => new Set(context.value?.commits.flatMap((c) => c.files) ?? []).size);

const title = computed(() => isManual.value ? 'Comentario manual del día' : 'Revisar comentario del agente');

const publish = async () => {
    if (!props.approval || !text.value.trim()) return;
    busy.value = 'publish';
    try {
        const approval = await agentService.approve(props.approval.id, text.value.trim(), alreadyPublished.value);
        toast.success(`Comentario publicado en el Work Item #${approval.work_item_id}`);
        refreshPendingApprovals();
        emit('resolved', approval);
        emit('close');
    } catch (e) {
        if (errorCode(e) === 'already_published') {
            alreadyPublished.value = true;
        } else {
            toast.error(errorMessage(e));
        }
    } finally {
        busy.value = null;
    }
};

const regenerate = async () => {
    if (!props.approval) return;
    busy.value = 'regenerate';
    try {
        const approval = await agentService.regenerate(props.approval.id);
        text.value = approval.proposal ?? '';
        emit('resolved', approval);
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        busy.value = null;
    }
};

const discard = async () => {
    if (!props.approval) return;
    busy.value = 'reject';
    try {
        const approval = await agentService.reject(props.approval.id);
        refreshPendingApprovals();
        emit('resolved', approval);
        emit('close');
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <AppModal
        :open="open && !!approval"
        :title="title"
        :subtitle="approval?.work_item ? `#${approval.work_item_id} · ${approval.work_item.title}` : ''"
        size="lg"
        @close="emit('close')"
    >
        <div v-if="approval && context" class="space-y-4">
            <!-- Sin commits -->
            <div v-if="isManual" class="flex items-start gap-2.5 p-3 rounded-lg bg-warning/10 border border-warning/30 text-body-sm text-on-surface">
                <span class="material-symbols-outlined text-warning text-[20px] shrink-0" aria-hidden="true">info</span>
                <p>
                    <template v-if="!context.pull_requests.length && !context.branches.length">
                        El Work Item no tiene Pull Request ni rama asociados.
                    </template>
                    <template v-else>
                        No se encontraron commits hoy ({{ context.date }}) en
                        {{ context.pull_requests.length ? 'el Pull Request' : 'la rama' }} asociado.
                    </template>
                    Escribe el comentario del día manualmente.
                </p>
            </div>

            <!-- Resumen de datos usados -->
            <div v-else class="rounded-lg border border-outline-variant bg-surface-container-low">
                <button
                    type="button"
                    class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left text-body-sm"
                    :aria-expanded="showContext"
                    @click="showContext = !showContext"
                >
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-on-surface">
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px] text-primary" aria-hidden="true">commit</span>
                            {{ context.commits.length }} commits hoy
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px] text-primary" aria-hidden="true">description</span>
                            {{ fileCount }} archivos
                        </span>
                        <span v-for="pr in context.pull_requests" :key="pr.id" class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-[16px] text-primary" aria-hidden="true">merge</span>
                            PR !{{ pr.id }}
                        </span>
                    </span>
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant transition-transform" :class="{ 'rotate-180': showContext }" aria-hidden="true">expand_more</span>
                </button>
                <div v-show="showContext" class="px-3 pb-3 space-y-2 text-[12px] text-on-surface-variant border-t border-outline-variant pt-2">
                    <p v-for="pr in context.pull_requests" :key="pr.id">
                        <a :href="pr.url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">PR !{{ pr.id }} {{ pr.title }}</a>
                        · {{ pr.sourceBranch }} → {{ pr.targetBranch }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="commit in context.commits" :key="commit.commitId" class="flex gap-2">
                            <span class="font-label-mono text-on-surface shrink-0">{{ commit.time }}</span>
                            <span class="min-w-0">
                                <span class="text-on-surface">{{ commit.message.split('\n')[0] }}</span>
                                <span class="font-label-mono"> · {{ commit.commitId.substring(0, 7) }} · {{ commit.author }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <p v-if="approval.fallback_reason" class="flex items-start gap-2 text-[12px] text-warning">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">smart_toy</span>
                Generado sin IA (plantilla): {{ approval.fallback_reason }}
            </p>
            <p v-for="warning in context.warnings" :key="warning" class="text-[12px] text-warning">{{ warning }}</p>

            <div>
                <label for="agent-comment-text" class="field-label">
                    {{ isManual ? 'Comentario' : 'Comentario propuesto (editable)' }}
                </label>
                <textarea
                    id="agent-comment-text"
                    v-model="text"
                    rows="12"
                    maxlength="10000"
                    class="field resize-y font-label-mono leading-relaxed"
                    :placeholder="isManual ? '¿Qué avances hubo hoy en este Work Item?' : ''"
                    autofocus
                    @keydown.ctrl.enter="publish"
                ></textarea>
                <p class="text-[11px] text-on-surface-variant mt-1">Se admite markdown (**negrita**, listas con -, `código`). Ctrl+Enter para publicar.</p>
            </div>

            <div v-if="alreadyPublished" class="flex items-start gap-2.5 p-3 rounded-lg bg-warning/10 border border-warning/30 text-body-sm text-on-surface" role="alert">
                <span class="material-symbols-outlined text-warning text-[20px] shrink-0" aria-hidden="true">warning</span>
                Ya se publicó hoy un comentario del agente en este Work Item. Presiona "Publicar de todas formas" para publicar otro.
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-ghost sm:mr-auto" :disabled="!!busy" @click="discard">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span>
                Descartar
            </button>
            <button v-if="!isManual" type="button" class="btn btn-secondary" :disabled="!!busy" @click="regenerate">
                <span class="material-symbols-outlined text-[16px]" :class="{ 'animate-spin': busy === 'regenerate' }" aria-hidden="true">autorenew</span>
                Regenerar
            </button>
            <button type="button" class="btn btn-primary" :disabled="!!busy || !text.trim()" @click="publish">
                <span v-if="busy === 'publish'" class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                <span v-else class="material-symbols-outlined text-[16px]" aria-hidden="true">send</span>
                {{ alreadyPublished ? 'Publicar de todas formas' : 'Publicar comentario' }}
            </button>
        </template>
    </AppModal>
</template>
