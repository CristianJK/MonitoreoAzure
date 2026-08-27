<script setup lang="ts">
import { ref } from 'vue';
import DashboardView from './views/DashboardView.vue';
import WorkItemView from './views/WorkItemView.vue';
import type { WorkItem } from './services/workItemService';

const selectedWorkItem = ref<WorkItem | null>(null);

const showWorkItem = (workItem: WorkItem) => {
  selectedWorkItem.value = workItem;
};

const showDashboard = () => {
  selectedWorkItem.value = null;
};
</script>

<template>
  <div class="min-h-screen bg-background text-on-background flex flex-col md:flex-row">
    
    <!-- Sidebar de Navegación -->
    <aside class="bg-surface-container-low border-r border-outline-variant fixed left-0 h-full w-64 hidden md:flex flex-col z-40">
      <div class="px-4 py-6 flex items-center gap-3 border-b border-outline-variant">
        <div class="w-10 h-10 bg-primary-container rounded-lg flex items-center justify-center text-on-primary-container">
          <span class="material-symbols-outlined text-[22px]">dashboard</span>
        </div>
        <div>
          <h2 class="font-semibold text-on-surface text-sm">Módulo de Gestión</h2>
          <p class="text-xs text-on-surface-variant">Azure DevOps Dashboard</p>
        </div>
      </div>
      <nav class="flex-1 py-4" aria-label="Navegación principal">
        <ul class="flex flex-col gap-1 px-2">
          <li>
            <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary-container/15 text-primary border-l-4 border-primary font-medium text-sm transition-colors hover:bg-primary-container/25">
              <span class="material-symbols-outlined text-[20px]">dashboard</span>
              <span>Overview</span>
            </a>
          </li>
        </ul>
      </nav>
      <div class="p-4 border-t border-outline-variant">
        <div class="flex items-center gap-3 px-2">
          <div class="w-8 h-8 rounded-full bg-surface-bright flex items-center justify-center text-xs font-bold text-on-surface border border-outline-variant">
            C
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-on-surface truncate">Cristian</p>
            <p class="text-xs text-on-surface-variant truncate">Developer</p>
          </div>
        </div>
      </div>
    </aside>

    <!-- Top Navbar -->
    <header class="bg-surface-container border-b border-outline-variant fixed top-0 left-0 right-0 h-14 z-50 flex justify-between items-center px-4 md:px-6 md:ml-64">
      <div class="flex items-center gap-3">
        <span class="font-semibold text-on-surface">Azure DevOps Local Dashboard</span>
      </div>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-bold text-sm">
          C
        </div>
      </div>
    </header>

    <!-- Vista Principal -->
    <div class="flex-1 flex flex-col pt-14 md:ml-64">
      <WorkItemView
        v-if="selectedWorkItem"
        :work-item="selectedWorkItem"
        @back="showDashboard"
      />
      <DashboardView v-else @select-work-item="showWorkItem" />
    </div>

  </div>
</template>