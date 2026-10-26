import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '@/views/DashboardView.vue'
import WorkItemView from '@/views/WorkItemView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
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

router.afterEach((to) => {
  const title = to.name === 'work-item' ? `#${to.params.id}` : to.meta.title
  document.title = title ? `${title} · Monitoreo Azure` : 'Monitoreo Azure'
})

export default router
