import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { onUnauthorized } from './services/apiClient'
import { useSession } from './composables/useSession'
import 'material-symbols/outlined.css'

// Sesión cerrada o PAT rechazado por Azure: volver al login conservando la ruta
onUnauthorized((code) => {
  const { currentUser, clearSession } = useSession()
  if (!currentUser.value) return
  clearSession()
  const current = router.currentRoute.value
  if (current.meta.public) return
  router.push({
    name: 'login',
    query: { redirect: current.fullPath, ...(code === 'azure_pat_invalid' ? { error: 'pat_invalido' } : {}) },
  })
})

createApp(App).use(router).mount('#app')
