import { setupLayouts } from 'virtual:meta-layouts'
import { createRouter, createWebHistory } from 'vue-router/auto'
import { useAuthStore } from '@/stores/auth'
import { store as piniaStore } from '../2.pinia.js'

function recursiveLayouts(route) {
  if (route.children) {
    for (let i = 0; i < route.children.length; i++)
      route.children[i] = recursiveLayouts(route.children[i])
    
    return route
  }
  
  return setupLayouts([route])[0]
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to) {
    if (to.hash)
      return { el: to.hash, behavior: 'smooth', top: 60 }
    
    return { top: 0 }
  },
  extendRoutes: pages => [
    ...[...pages].map(route => recursiveLayouts(route)),
  ],
})

router.beforeEach(async to => {
  const auth = useAuthStore(piniaStore)

  if (!auth.hasCheckedAuth)
    await auth.fetchUser()

  const isPublicRoute = to.matched.some(record => record.meta?.public === true)
  const isGuestOnly = to.matched.some(record => record.meta?.guestOnly === true)
  const requiresAuth = !isPublicRoute

  if (requiresAuth && !auth.isAuthenticated)
    return { path: '/login', query: { redirect: to.fullPath } }

  if (isGuestOnly && auth.isAuthenticated)
    return { path: '/' }
})

export { router }
export default function (app) {
  app.use(router)
}
