<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { workItemService, type WorkItem } from '@/services/workItemService';

const props = defineProps<{
    workItem: WorkItem;
}>();

const emit = defineEmits<{
    back: [];
}>();

const fullWorkItem = ref<WorkItem | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const workItemData = computed(() => fullWorkItem.value || props.workItem);

const stateColor = computed(() => {
    const state = workItemData.value.state?.toLowerCase() || '';
    if (state === 'done' || state === 'closed') return 'bg-success/20 text-success border-success/30';
    if (state === 'in progress') return 'bg-primary-container/20 text-primary border-primary/30';
    if (state === 'to do' || state === 'new') return 'bg-secondary/20 text-secondary border-secondary/30';
    return 'bg-surface-variant text-on-surface-variant border-outline-variant';
});

const stateDotColor = computed(() => {
    const state = workItemData.value.state?.toLowerCase() || '';
    if (state === 'done' || state === 'closed') return 'bg-success';
    if (state === 'in progress') return 'bg-primary';
    if (state === 'to do' || state === 'new') return 'bg-secondary';
    return 'bg-on-surface-variant';
});

const typeIcon = computed(() => {
    const type = workItemData.value.type?.toLowerCase() || '';
    if (type.includes('bug')) return 'bug_report';
    if (type.includes('product')) return 'inventory_2';
    return 'task';
});

const typeColor = computed(() => {
    const type = workItemData.value.type?.toLowerCase() || '';
    if (type.includes('bug')) return 'text-error';
    if (type.includes('product')) return 'text-secondary';
    return 'text-primary';
});

const commentsCount = computed(() => {
    return (workItemData.value.comments?.length || 0) + (workItemData.value.history?.length || 0);
});

const formatDate = (dateString: string | undefined) => {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        return date.toLocaleString('es-ES', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch {
        return dateString;
    }
};

const getInitials = (name: string | undefined) => {
    if (!name) return '?';
    const parts = name.split(' ');
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
};

const loadDetails = async () => {
    loading.value = true;
    error.value = null;
    try {
        fullWorkItem.value = await workItemService.getWorkItemDetails(workItemData.value.id);
    } catch (e: any) {
        error.value = e?.message || 'Error al cargar los detalles del Work Item';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadDetails();
});
</script>

<template>
    <div class="flex-1 flex flex-col min-h-screen bg-background overflow-hidden">
        <!-- Breadcrumb Header -->
        <div class="bg-surface-container-high border-b border-outline-variant px-4 md:px-6 py-3 shrink-0">
            <nav class="flex items-center gap-1.5 font-label-mono text-label-mono text-on-surface-variant text-xs" aria-label="Breadcrumb">
                <button
                    class="flex items-center gap-1.5 text-primary hover:text-on-surface transition-colors"
                    title="Volver al dashboard"
                    @click="emit('back')"
                >
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_back</span>
                    <span>Dashboard</span>
                </button>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>Boards</span>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>Work Items</span>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span class="text-primary font-semibold">#{{ workItem.id }}</span>
            </nav>
        </div>

        <!-- Title Section -->
        <div class="bg-surface-container-high border-b border-outline-variant px-4 md:px-6 py-4 shrink-0">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <h1 class="text-on-surface font-bold text-headline-lg leading-tight">
                        {{ workItem.title }}
                    </h1>
                    <div class="flex items-center gap-2.5 mt-4">
                        
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border"
                            :class="stateColor"
                        >
                            <span class="w-1.5 h-1.5 rounded-full" :class="stateDotColor" aria-hidden="true"></span>
                            {{ workItem.state }}
                        </span>
                        <span class="flex items-center gap-1 text-on-surface-variant text-xs">
                            <span class="material-symbols-outlined text-[14px]" :class="typeColor" aria-hidden="true">{{ typeIcon }}</span>
                            {{ workItem.type }}
                        </span>
                    </div>
                    
                </div>
                <div class="flex gap-3 shrink-0">      
                    <div class="relative">                        
                        <select
                            id="work-item-state"
                            class="w-full bg-background border border-outline-variant rounded-lg p-2.5 text-on-surface font-body-sm text-body-sm appearance-none input-glow cursor-pointer pr-8"
                            :value="workItem.state"
                        >
                            <option>New</option>
                            <option>To Do</option>
                            <option>In Progress</option>
                            <option>In Review</option>
                            <option>Done</option>
                            <option>Removed</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none text-[18px]" aria-hidden="true">expand_more</span>
                    </div>              
                    <button
                        class="flex items-center gap-2 px-4 py-2 bg-primary-container hover:opacity-90 text-on-primary-container text-sm font-medium rounded-lg transition-opacity shadow-sm"
                    >
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">play_arrow</span>
                        <span class="hidden sm:inline">Run Azure Agent</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 2-Column Layout -->
        <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
            <!-- Left Column: Details & Content -->
            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 md:p-6 flex flex-col gap-6 pb-8">
                <!-- Description Section -->
                <section class="bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                    <div class="bg-surface-variant border-b border-outline-variant px-4 py-3 flex justify-between items-center">
                        <h2 class="font-headline-sm text-headline-sm text-on-surface">Description</h2>
                    </div>                    
                    <div
                        class="p-4 font-body-md text-body-md text-on-surface leading-relaxed min-h-[180px] outline-none"                        
                        aria-label="Description content"
                    >
                        <div v-if="loading" class="text-on-surface-variant text-sm">Cargando descripción...</div>
                        <div v-else-if="error" class="text-error text-sm">{{ error }}</div>
                        <div v-else-if="workItemData.description && workItemData.description.trim()" v-html="workItemData.description" class="prose prose-sm max-w-none text-on-surface"></div>
                        <p v-else class="text-on-surface-variant italic">Sin descripción</p>
                    </div>
                </section>

                <!-- Discussion Section -->
                <section class="flex flex-col gap-4">
                    <div class="flex items-center justify-between border-b border-outline-variant pb-2">
                        <h2 class="font-headline-sm text-headline-sm text-on-surface">Discussion / History</h2>
                        <span class="bg-surface-variant text-on-surface-variant px-2.5 py-0.5 rounded-full font-label-mono text-[10px]">{{ commentsCount }} elementos</span>
                    </div>

                    <!-- Comment Feed -->
                    <div class="flex flex-col gap-4">
                        <!-- Comments -->
                        <template v-if="workItemData.comments && workItemData.comments.length > 0">
                            <div v-for="comment in workItemData.comments" :key="comment.id" class="flex gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center text-on-secondary-container text-xs font-bold shrink-0 border border-outline-variant">
                                    {{ getInitials(comment.createdBy?.displayName) }}
                                </div>
                                <div class="flex-1 bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                                    <div class="px-4 py-2.5 border-b border-outline-variant flex justify-between items-center bg-surface-variant">
                                        <div class="flex items-center gap-2">
                                            <span class="font-body-sm text-body-sm font-semibold text-on-surface">{{ comment.createdBy?.displayName || 'Usuario' }}</span>
                                            <span class="text-on-surface-variant text-[11px]">{{ formatDate(comment.createdDate) }}</span>
                                        </div>
                                    </div>
                                    <div class="p-4 font-body-sm text-body-sm text-on-surface leading-relaxed" v-html="comment.text"></div>
                                </div>
                            </div>
                        </template>

                        <!-- History / System Actions -->
                        <template v-if="workItemData.history && workItemData.history.length > 0">
                            <div v-for="update in workItemData.history" :key="update.id" class="flex items-center gap-2 pl-11 py-1">
                                <span class="material-symbols-outlined text-[14px] text-on-surface-variant" aria-hidden="true">change_circle</span>
                                <span class="font-body-sm text-body-sm text-on-surface-variant">
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
                                </span>
                            </div>
                        </template>

                        <p v-if="(!workItemData.comments || workItemData.comments.length === 0) && (!workItemData.history || workItemData.history.length === 0)" class="text-on-surface-variant italic text-sm pl-2">
                            Sin comentarios ni historial
                        </p>
                    </div>

                    <!-- Add Comment Area -->
                    <div class="mt-2 flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-surface-bright flex items-center justify-center text-on-surface text-xs font-bold shrink-0 border border-outline-variant">
                            C
                        </div>
                        <div class="flex-1 bg-background border border-outline-variant rounded-xl input-glow focus-within:bg-surface-container transition-colors">
                            <textarea
                                class="w-full bg-transparent border-none text-on-surface font-body-sm text-body-sm p-3 min-h-[80px] focus:ring-0 resize-y custom-scrollbar"
                                placeholder="Agregar un comentario... Escribe @ para mencionar a alguien."
                                aria-label="Add a comment"
                            ></textarea>
                            <div class="flex justify-between items-center px-3 py-2 border-t border-outline-variant bg-surface-variant rounded-b-xl">
                                <div class="flex gap-1">
                                    <button
                                        class="p-1.5 text-on-surface-variant hover:text-primary hover:bg-background rounded-lg transition-colors"
                                        title="Adjuntar archivo"
                                        aria-label="Attach file"
                                    >
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">attach_file</span>
                                    </button>
                                    <button
                                        class="p-1.5 text-on-surface-variant hover:text-primary hover:bg-background rounded-lg transition-colors"
                                        title="Mencionar"
                                        aria-label="Mention someone"
                                    >
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">alternate_email</span>
                                    </button>
                                </div>
                                <button class="px-3 py-1.5 bg-primary-container text-on-primary-container font-body-sm text-body-sm rounded-lg hover:opacity-90 transition-opacity">
                                    Guardar
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Right Column: Metadata / Sidebar -->
            <aside class="w-full lg:w-80 border-t lg:border-t-0 lg:border-l border-outline-variant bg-surface-container-highest overflow-y-auto custom-scrollbar flex flex-col shrink-0">
                <!-- Work Item Info -->
                <div class="p-4 border-b border-outline-variant bg-surface-container-high">
                    <h3 class="font-label-caps text-label-caps text-primary font-bold mb-3 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                        Información
                    </h3>
                    <div class="flex flex-col gap-2 text-xs">
                        <div v-if="workItemData.created_by" class="flex flex-col">
                            <span class="text-on-surface-variant">Creado por</span>
                            <span class="text-on-surface">{{ workItemData.created_by.displayName }}</span>
                        </div>
                        <div v-if="workItemData.created_date" class="flex flex-col">
                            <span class="text-on-surface-variant">Creado</span>
                            <span class="text-on-surface">{{ formatDate(workItemData.created_date) }}</span>
                        </div>
                        <div v-if="workItemData.changed_by" class="flex flex-col">
                            <span class="text-on-surface-variant">Actualizado por</span>
                            <span class="text-on-surface">{{ workItemData.changed_by.displayName }}</span>
                        </div>
                        <div v-if="workItemData.changed_date" class="flex flex-col">
                            <span class="text-on-surface-variant">Actualizado</span>
                            <span class="text-on-surface">{{ formatDate(workItemData.changed_date) }}</span>
                        </div>
                        <div v-if="workItemData.area_path" class="flex flex-col">
                            <span class="text-on-surface-variant">Area Path</span>
                            <span class="text-on-surface truncate" :title="workItemData.area_path">{{ workItemData.area_path }}</span>
                        </div>
                        <div v-if="workItemData.iteration_path" class="flex flex-col">
                            <span class="text-on-surface-variant">Iteration Path</span>
                            <span class="text-on-surface truncate" :title="workItemData.iteration_path">{{ workItemData.iteration_path }}</span>
                        </div>
                    </div>
                </div>
                <!-- Production Readiness Checklist -->
                <div class="flex-1 p-4 border-b border-outline-variant bg-surface-container-high relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-primary" aria-hidden="true"></div>
                    <h3 class="font-label-caps text-label-caps text-primary font-bold mb-3 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">fact_check</span>
                        Production Readiness
                    </h3>
                    <div class="flex flex-col gap-2">
                        <!-- WO creacion de rama -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-success text-[16px] shrink-0 mt-[2px]" aria-hidden="true">check_circle</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Solicitud de Rama</span>
                                    <a class="text-primary text-[11px] hover:underline" href="#">#WO-12334566789</a>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to PR"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>
                        <!-- PR Associated -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-success text-[16px] shrink-0 mt-[2px]" aria-hidden="true">check_circle</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Pull Request Asociado</span>
                                    <a class="text-primary text-[11px] hover:underline" href="#">PR #4920 merged</a>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to PR"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>

                        <!-- Documentation -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-on-surface-variant text-[16px] shrink-0 mt-[2px]" aria-hidden="true">radio_button_unchecked</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Manual Tecnico</span>
                                    <span class="text-on-surface-variant text-[11px]">Sin manual tecnico</span>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to documentation"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>

                        <!-- Documentation -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-on-surface-variant text-[16px] shrink-0 mt-[2px]" aria-hidden="true">radio_button_unchecked</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Pruebas Unitarias</span>
                                    <span class="text-on-surface-variant text-[11px]">Sin pruebas unitarias</span>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to documentation"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>

                        <!-- Documentation -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-on-surface-variant text-[16px] shrink-0 mt-[2px]" aria-hidden="true">radio_button_unchecked</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Minutograma</span>
                                    <span class="text-on-surface-variant text-[11px]">Sin minutograma</span>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to documentation"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>

                        <!-- Standards Validation -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">                                
                                <span class="material-symbols-outlined text-on-surface-variant text-[16px] shrink-0 mt-[2px]" aria-hidden="true">radio_button_unchecked</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Validación de Estándares</span>
                                    <!--<span class="text-success text-[11px]">Aprobado</span>-->
                                    <span class="text-on-surface-variant text-[11px]">Sin cumplir con estándares</span>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to standards"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>

                        <!-- Pipeline Status -->
                        <div class="flex flex-col gap-1 p-2.5 bg-surface-variant rounded-lg border border-outline-variant group">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-warning text-[16px] shrink-0 mt-[2px]" aria-hidden="true">warning</span>
                                <div class="flex-1 flex flex-col min-w-0">
                                    <span class="font-body-sm text-body-sm font-semibold text-on-surface leading-tight">Estado del Pipeline</span>
                                    <span class="text-warning text-[11px]">CI tests fallando</span>
                                </div>
                                <button
                                    class="text-on-surface-variant hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded"
                                    aria-label="Add comment to pipeline"
                                >
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">add_comment</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- System Info -->
                <div class="mt-auto p-4 border-t border-outline-variant text-on-surface-variant font-label-mono text-[10px] space-y-2">
                    <label class="font-label-mono text-label-mono text-on-surface-variant text-xs" for="work-item-assignee">Assigned To</label>
                    <div class="flex items-center gap-2.5 p-2.5 bg-surface-variant rounded-lg">
                        <div class="w-6 h-6 rounded-full bg-primary-container flex items-center justify-center text-[10px] font-bold text-on-primary-container border border-outline-variant">
                            {{ getInitials(workItemData.assigned_to) }}
                        </div>
                        <span class="font-body-sm text-body-sm text-on-surface flex-1 truncate">{{ workItemData.assigned_to }}</span>
                    </div>
                    <p v-if="workItemData.changed_date" class="opacity-60">Actualizado: {{ formatDate(workItemData.changed_date) }}</p>
                </div>
            </aside>
        </div>
    </div>
</template>
