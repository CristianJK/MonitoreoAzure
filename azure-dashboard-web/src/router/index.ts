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
    },
    {
      path: '/work-items/:id',
      name: 'work-item',
      component: WorkItemView,
      props: true,
    },
  ],
})

export default router
