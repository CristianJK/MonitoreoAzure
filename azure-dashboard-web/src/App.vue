<script setup lang="ts">
import DashboardView from './views/DashboardView.vue';
</script>

<template>
  <div class="min-h-screen bg-background text-on-background flex flex-col md:flex-row">
    
    <!-- Sidebar de Navegación -->
    <aside class="bg-surface-container-low border-r border-outline-variant fixed left-0 h-full w-64 hidden md:flex flex-col z-40">
      <div class="px-4 py-6 flex items-center gap-3 border-b border-outline-variant">
        <div class="w-10 h-10 bg-primary-container rounded flex items-center justify-center text-on-primary-container font-bold">
          🚀
        </div>
        <div>
          <h2 class="font-semibold text-on-surface">Módulo de Gestión</h2>
          <p class="text-xs text-on-surface-variant">Azure DevOps Dashboard</p>
        </div>
      </div>
      <nav class="flex-1 py-4">
        <ul class="flex flex-col gap-1 px-2">
          <li>
            <a href="#" class="flex items-center gap-3 px-3 py-2 rounded-md bg-secondary-container text-on-secondary-container border-l-4 border-primary font-medium text-sm">
              <span class="material-symbols-outlined color-white">dashboard</span>
              <span class="text-white">Overview</span>
            </a>
          </li>
        </ul>
      </nav>
    </aside>

    <!-- Top Navbar -->
    <header class="bg-surface-container border-b border-outline-variant fixed top-0 left-0 right-0 h-16 z-50 flex justify-between items-center px-6">
      <div class="flex items-center gap-4">
        <span class="font-bold text-lg text-on-surface">Azure DevOps Local Dashboard</span>
      </div>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center font-bold text-sm">
          C
        </div>
      </div>
    </header>

    <!-- Vista Principal (Dashboard) -->
    <div class="flex-1 flex flex-col">
      <DashboardView />
    </div>

  </div>
</template>