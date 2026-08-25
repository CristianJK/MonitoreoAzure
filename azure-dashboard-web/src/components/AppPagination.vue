<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    page: number;
    totalPages: number;
    totalItems?: number;
    itemsPerPage?: number;
    isLoading?: boolean;
    siblingCount?: number;
}

const props = withDefaults(defineProps<Props>(), {
  totalItems: 0,
  itemsPerPage: 10,
  isLoading: false,
  siblingCount: 1,
});

const emit = defineEmits<{
  (e: 'update:page', value: number): void;
}>();

// 3. Algoritmo para generar el rango de páginas con elipses (...)
const paginationRange = computed(() => {
  const totalPageNumbers = props.siblingCount + 5; // siblingCount + firstPage + lastPage + currentPage + 2*dots

  // Caso 1: Si el número de páginas es menor a los bloques que queremos mostrar
  if (totalPageNumbers >= props.totalPages) {
    return Array.from({ length: props.totalPages }, (_, i) => i + 1);
  }

  const leftSiblingIndex = Math.max(props.page - props.siblingCount, 1);
  const rightSiblingIndex = Math.min(props.page + props.siblingCount, props.totalPages);

  const shouldShowLeftDots = leftSiblingIndex > 2;
  const shouldShowRightDots = rightSiblingIndex < props.totalPages - 2;

  const firstPageIndex = 1;
  const lastPageIndex = props.totalPages;

  // Caso 2: No hay puntos a la izquierda, pero sí a la derecha
  if (!shouldShowLeftDots && shouldShowRightDots) {
    const leftItemCount = 3 + 2 * props.siblingCount;
    const leftRange = Array.from({ length: leftItemCount }, (_, i) => i + 1);
    return [...leftRange, '...', props.totalPages];
  }

  // Caso 3: No hay puntos a la derecha, pero sí a la izquierda
  if (shouldShowLeftDots && !shouldShowRightDots) {
    const rightItemCount = 3 + 2 * props.siblingCount;
    const rightRange = Array.from(
      { length: rightItemCount },
      (_, i) => props.totalPages - rightItemCount + i + 1
    );
    return [firstPageIndex, '...', ...rightRange];
  }

  // Caso 4: Hay puntos a ambos lados
  if (shouldShowLeftDots && shouldShowRightDots) {
    const middleRange = Array.from(
      { length: rightSiblingIndex - leftSiblingIndex + 1 },
      (_, i) => leftSiblingIndex + i
    );
    return [firstPageIndex, '...', ...middleRange, '...', lastPageIndex];
  }

  return [];
});

// 4. Cambiar de página respetando límites y estado de carga
const changePage = (newPage: number | string) => {
  if (typeof newPage !== 'number' || props.isLoading) return;
  if (newPage >= 1 && newPage <= props.totalPages && newPage !== props.page) {
    emit('update:page', newPage);
  }
};

// 5. Cálculos para el texto descriptivo
const startItem = computed(() => ((props.page - 1) * props.itemsPerPage) + 1);
const endItem = computed(() => Math.min(props.page * props.itemsPerPage, props.totalItems));
</script>

<template>
    <nav
        v-if="totalPages > 0" 
        class="flex flex-col sm:flex-row justify-between items-center p-4 border-t border-outline-variant bg-surface-container-low gap-4"
        aria-label="Paginación">
        <!-- Texto informativo de registros -->
        <div class="text-sm text-on-surface-variant">
            <template v-if="totalItems > 0">
                Mostrando <span class="font-medium text-on-surface">{{ startItem }}</span> - 
                <span class="font-medium text-on-surface">{{ endItem }}</span> de 
                <span class="font-medium text-on-surface">{{ totalItems }}</span> resultados
            </template>
            <template v-else>
                Página {{ page }} de {{ totalPages }}
            </template>
        </div>

        <!-- Controles de navegación -->
        <div class="flex items-center gap-1">
        <!-- Botón Anterior -->
        <button 
            class="px-2 py-1 rounded-md bg-surface-bright hover:bg-surface text-on-surface transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center min-w-[36px] h-9" 
            :disabled="page === 1 || isLoading"
            @click="changePage(page - 1)"
            aria-label="Ir a la página anterior"
        >
            <span class="material-symbols-outlined text-lg">chevron_left</span>
        </button>

        <!-- Botones numerados y elipses -->
        <template v-for="(item, index) in paginationRange" :key="index">
            <span 
            v-if="item === '...'" 
            class="px-2 py-1 text-on-surface-variant text-sm select-none"
            >
            •••
            </span>

            <button 
            v-else
            class="px-3 py-1 rounded-md text-sm font-medium transition-colors h-9 min-w-[36px] flex items-center justify-center"
            :class="[
                item === page 
                ? 'bg-primary text-on-primary shadow-sm' 
                : 'bg-surface-bright hover:bg-surface text-on-surface'
            ]"
            :disabled="isLoading"
            :aria-current="item === page ? 'page' : undefined"
            @click="changePage(item)"
            >
            {{ item }}
            </button>
        </template>

        <!-- Botón Siguiente -->
        <button 
            class="px-2 py-1 rounded-md bg-surface-bright hover:bg-surface text-on-surface transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center min-w-[36px] h-9" 
            :disabled="page === totalPages || isLoading"
            @click="changePage(page + 1)"
            aria-label="Ir a la página siguiente"
        >
            <span class="material-symbols-outlined text-lg">chevron_right</span>
        </button>
        </div>        
    </nav>
</template>
