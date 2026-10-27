import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '@/views/DashboardView.vue'
import WorkItemView from '@/views/WorkItemView.vue'
import { useSession } from '@/composables/useSession'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    /** No requiere sesión (solo el login) */
    public?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: 'Iniciar sesión', public: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: { title: 'Dashboard' },
    },
    {
      path: '/work-items/:id(\\d+)',
      name: 'work-item',
      component: WorkItemView,
      props: true,
      meta: { title: 'Work Item' },
    },
    {
      path: '/alistamiento',
      name: 'checklist-definitions',
      component: () => import('@/views/ChecklistDefinitionsView.vue'),
      meta: { title: 'Alistamiento' },
    },
    {
      path: '/agentes',
      name: 'agent-inbox',
      component: () => import('@/views/AgentInboxView.vue'),
      meta: { title: 'Comentarios por revisar' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: { name: 'dashboard' },
    },
  ],
})

// Toda la aplicación requiere sesión; el backend vuelve a validar cada petición
router.beforeEach(async (to) => {
  const user = await useSession().loadCurrentUser()

  if (!user && !to.meta.public) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (user && to.name === 'login') {
    return { path: (to.query.redirect as string) || '/' }
  }
})

router.afterEach((to) => {
  const title = to.name === 'work-item' ? `#${to.params.id}` : to.meta.title
  document.title = title ? `${title} · Monitoreo Azure` : 'Monitoreo Azure'
})

export default router
