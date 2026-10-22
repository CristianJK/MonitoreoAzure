<script setup lang="ts">
import { computed } from 'vue';
import type { WorkItem } from '@/services/workItemService';

const props = defineProps<{
    workItem: WorkItem;
}>();

const emit = defineEmits<{
    back: [];
}>();

const stateColor = computed(() => {
    const state = props.workItem.state?.toLowerCase() || '';
    if (state === 'done') return 'bg-success/20 text-success border-success/30';
    if (state === 'in progress') return 'bg-primary-container/20 text-primary border-primary/30';
    if (state === 'to do') return 'bg-secondary/20 text-secondary border-secondary/30';
    return 'bg-surface-variant text-on-surface-variant border-outline-variant';
});

const stateDotColor = computed(() => {
    const state = props.workItem.state?.toLowerCase() || '';
    if (state === 'done') return 'bg-success';
    if (state === 'in progress') return 'bg-primary';
    if (state === 'to do') return 'bg-secondary';
    return 'bg-on-surface-variant';
});

const typeIcon = computed(() => {
    const type = props.workItem.type?.toLowerCase() || '';
    if (type.includes('bug')) return 'bug_report';
    if (type.includes('product')) return 'inventory_2';
    return 'task';
});

const typeColor = computed(() => {
    const type = props.workItem.type?.toLowerCase() || '';
    if (type.includes('bug')) return 'text-error';
    if (type.includes('product')) return 'text-secondary';
    return 'text-primary';
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
                        <p class="mb-3">Implementar la lógica de generación de PINs versión 3.0 para el módulo ARQ-VTA.</p>
                        <p class="mb-3">Requisitos:</p>
                        <ul class="list-disc pl-5 mb-3 space-y-1.5 text-on-surface-variant">
                            <li>Actualizar el generador de números aleatorios criptográficamente seguro (CSPRNG).</li>
                            <li>Garantizar compatibilidad retroactiva con APIs V2 durante la fase de transición.</li>
                            <li>Agregar pruebas unitarias comprehensivas para casos extremos (ej: secuencias repetidas).</li>
                            <li>Registrar eventos de generación de forma segura (excluir el PIN real de los logs).</li>
                        </ul>
                        <p>Documento de referencia arquitectónica: <a class="text-primary hover:underline" href="#">VTA-Security-Specs-v3.pdf</a></p>
                    </div>
                </section>

                <!-- Discussion Section -->
                <section class="flex flex-col gap-4">
                    <div class="flex items-center justify-between border-b border-outline-variant pb-2">
                        <h2 class="font-headline-sm text-headline-sm text-on-surface">Discussion</h2>
                        <span class="bg-surface-variant text-on-surface-variant px-2.5 py-0.5 rounded-full font-label-mono text-[10px]">3 Comments</span>
                    </div>

                    <!-- Comment Feed -->
                    <div class="flex flex-col gap-4">
                        <!-- Comment 1 -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center text-on-secondary-container text-xs font-bold shrink-0 border border-outline-variant">
                                SC
                            </div>
                            <div class="flex-1 bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                                <div class="px-4 py-2.5 border-b border-outline-variant flex justify-between items-center bg-surface-variant">
                                    <div class="flex items-center gap-2">
                                        <span class="font-body-sm text-body-sm font-semibold text-on-surface">Sarah Chen</span>
                                        <span class="text-on-surface-variant text-[11px]">hace 1 día a las 14:32</span>
                                    </div>
                                    <button class="text-on-surface-variant hover:text-primary p-1 rounded transition-colors" aria-label="Edit comment">
                                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">edit</span>
                                    </button>
                                </div>
                                <div class="p-4 font-body-sm text-body-sm text-on-surface leading-relaxed">
                                    He revisado las especificaciones V3. El requisito de registro necesita aclaración respecto al cumplimiento de GDPR. Etiquetaré al equipo legal en el PR.
                                </div>
                            </div>
                        </div>

                        <!-- System Action -->
                        <div class="flex items-center gap-2 pl-11 py-1">
                            <span class="material-symbols-outlined text-[14px] text-on-surface-variant" aria-hidden="true">change_circle</span>
                            <span class="font-body-sm text-body-sm text-on-surface-variant">
                                <strong class="text-on-surface">David Miller</strong> cambió estado de
                                <span class="line-through">To Do</span> a
                                <strong class="text-primary">In Progress</strong>
                                <span class="text-[11px] ml-1.5">hoy a las 09:15</span>
                            </span>
                        </div>

                        <!-- Comment 2 -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container text-xs font-bold shrink-0">
                                DM
                            </div>
                            <div class="flex-1 bg-surface-container rounded-xl border border-outline-variant overflow-hidden">
                                <div class="px-4 py-2.5 border-b border-outline-variant flex justify-between items-center bg-surface-variant">
                                    <div class="flex items-center gap-2">
                                        <span class="font-body-sm text-body-sm font-semibold text-primary">David Miller</span>
                                        <span class="text-on-surface-variant text-[11px]">hoy a las 09:45</span>
                                    </div>
                                    <button class="text-on-surface-variant hover:text-primary p-1 rounded transition-colors" aria-label="Edit comment">
                                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">edit</span>
                                    </button>
                                </div>
                                <div class="p-4 font-body-sm text-body-sm text-on-surface leading-relaxed">
                                    @Sarah Buen punto. He iniciado la implementación de la actualización del CSPRNG. La rama es <code class="px-1.5 py-0.5 bg-surface-variant rounded text-primary text-xs">feature/arq-vta-pin-v3</code>.
                                </div>
                            </div>
                        </div>
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
                <div class="mt-auto p-4 border-t border-outline-variant text-on-surface-variant font-label-mono text-[10px] space-y-1 opacity-60">
                    <p>Creado por System Admin el Oct 24, 2023</p>
                    <p>Actualizado por David Miller hace 2 hrs</p>
                    <label class="font-label-mono text-label-mono text-on-surface-variant text-xs" for="work-item-assignee">Assigned To</label>
                    <div class="flex items-center gap-2.5 p-2.5">
                        <div class="w-6 h-6 rounded-full bg-surface-bright flex items-center justify-center text-[10px] font-bold text-on-surface border border-outline-variant">
                            {{ workItem.assigned_to?.charAt(0) || '?' }}
                        </div>
                        <span class="font-body-sm text-body-sm text-on-surface flex-1 truncate">{{ workItem.assigned_to }}</span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
