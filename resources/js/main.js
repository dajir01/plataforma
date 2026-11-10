import { createApp } from 'vue'
import App from '@/App.vue'
import { registerPlugins } from '@core/utils/plugins'
import axios from 'axios'

// Styles
import '@core-scss/template/index.scss'
import '@styles/styles.scss'

// Configurar axios globalmente
axios.defaults.headers.common['Accept'] = 'application/json'
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// Interceptor global: si la sesión expira (401), redirigir al login
axios.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      window.location.href = '/login?timeout=1'
    }
    return Promise.reject(error)
  },
)

// Create vue app
const app = createApp(App)


// Register plugins
registerPlugins(app)

// Mount vue app
app.mount('#app')
