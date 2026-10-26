<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ToastHost from '@/components/ToastHost.vue';
import { initials } from '@/composables/useWorkItemStyles';
import { useSession } from '@/composables/useSession';

const route = useRoute();
const { currentUser, pendingApprovals, loadCurrentUser, refreshPendingApprovals } = useSession();
const menuOpen = ref(false);

const navigation = [
  { name: 'dashboard', label: 'Overview', icon: 'dashboard' },
  { name: 'checklist-definitions', label: 'Alistamiento', icon: 'fact_check' },
  { name: 'agent-inbox', label: 'Comentarios por revisar', icon: 'smart_toy', badge: true },
] as const;

onMounted(() => {
  loadCurrentUser();
  refreshPendingApprovals();
});

watch(() => route.fullPath, () => {
  menuOpen.value = false;
});

const isActive = (name: string) =>
  route.name === name || (name === 'dashboard' && route.name === 'work-item');
</script>

<template>
  <div class="min-h-screen bg-background text-on-background flex flex-col md:flex-row">

    <!-- Fondo del menú en móvil -->
    <div v-if="menuOpen" class="fixed inset-0 bg-black/50 z-40 md:hidden" aria-hidden="true" @click="menuOpen = false"></div>

    <!-- Sidebar de Navegación -->
    <aside
      id="main-sidebar"
      class="bg-surface-container-low border-r border-outline-variant fixed left-0 top-0 h-full w-64 flex flex-col z-50 transition-transform md:translate-x-0"
      :class="menuOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="px-4 h-14 md:h-auto md:py-6 flex items-center gap-3 border-b border-outline-variant">
        <div class="w-10 h-10 bg-primary-container rounded-lg flex items-center justify-center text-on-primary-container shrink-0">
          <span class="material-symbols-outlined text-[22px]" aria-hidden="true">dashboard</span>
        </div>
        <div class="min-w-0">
          <h2 class="font-semibold text-on-surface text-sm">Módulo de Gestión</h2>
          <p class="text-xs text-on-surface-variant">Azure DevOps Dashboard</p>
        </div>
      </div>
      <nav class="flex-1 py-4" aria-label="Navegación principal">
        <ul class="flex flex-col gap-1 px-2">
          <li v-for="item in navigation" :key="item.name">
            <router-link
              :to="{ name: item.name }"
              class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors border-l-4"
              :class="isActive(item.name)
                ? 'bg-primary-container/15 text-primary border-primary font-medium'
                : 'border-transparent text-on-surface-variant hover:bg-surface-variant hover:text-on-surface'"
              :aria-current="isActive(item.name) ? 'page' : undefined"
            >
              <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ item.icon }}</span>
              <span class="flex-1">{{ item.label }}</span>
              <span
                v-if="'badge' in item && pendingApprovals > 0"
                class="bg-primary-container text-on-primary-container text-[11px] font-bold rounded-full min-w-5 h-5 px-1.5 flex items-center justify-center"
                :aria-label="`${pendingApprovals} pendientes`"
              >
                {{ pendingApprovals }}
              </span>
            </router-link>
          </li>
        </ul>
      </nav>
      <div class="p-4 border-t border-outline-variant">
        <div class="flex items-center gap-3 px-2">
          <div class="w-8 h-8 rounded-full bg-surface-bright flex items-center justify-center text-xs font-bold text-on-surface border border-outline-variant shrink-0">
            {{ initials(currentUser?.displayName) }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-on-surface truncate">{{ currentUser?.displayName ?? 'Cargando...' }}</p>
            <p class="text-xs text-on-surface-variant truncate">{{ currentUser?.uniqueName ?? 'Dueño del PAT' }}</p>
          </div>
        </div>
      </div>
    </aside>

    <!-- Top Navbar -->
    <header class="bg-surface-container border-b border-outline-variant fixed top-0 left-0 right-0 h-14 z-30 flex justify-between items-center px-4 md:px-6 md:ml-64">
      <div class="flex items-center gap-3 min-w-0">
        <button
          type="button"
          class="md:hidden p-1.5 -ml-1.5 rounded-lg text-on-surface hover:bg-surface-variant"
          aria-label="Abrir menú"
          aria-controls="main-sidebar"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >
          <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>
        <span class="font-semibold text-on-surface truncate">Azure DevOps<span class="hidden sm:inline"> Local Dashboard</span></span>
      </div>
      <div class="flex items-center gap-3">
        <router-link
          v-if="pendingApprovals > 0"
          :to="{ name: 'agent-inbox' }"
          class="relative p-1.5 rounded-lg text-on-surface-variant hover:text-primary hover:bg-surface-variant"
          :title="`${pendingApprovals} comentario(s) por revisar`"
        >
          <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
          <span class="absolute top-0.5 right-0.5 w-2 h-2 rounded-full bg-primary" aria-hidden="true"></span>
          <span class="sr-only">{{ pendingApprovals }} comentarios por revisar</span>
        </router-link>
        <div
          class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-bold text-sm"
          :title="currentUser?.displayName"
        >
          {{ initials(currentUser?.displayName) }}
        </div>
      </div>
    </header>

    <!-- Vista Principal -->
    <div class="flex-1 flex flex-col pt-14 md:ml-64 min-w-0">
      <router-view />
    </div>

    <ToastHost />
  </div>
</template>
