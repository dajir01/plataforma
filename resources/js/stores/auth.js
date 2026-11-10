import { defineStore } from 'pinia'

const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
const setCsrfToken = token => {
  if (!token)
    return

  const meta = document.querySelector('meta[name="csrf-token"]')
  if (meta)
    meta.setAttribute('content', token)
}

const refreshCsrfToken = async () => {
  try {
    const response = await fetch('/csrf-token', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok)
      return null

    const data = await response.json().catch(() => null)

    if (data?.token)
      setCsrfToken(data.token)

    return data?.token || null
  }
  catch (error) {
    console.error('Unable to refresh CSRF token', error)
    return null
  }
}

const jsonHeaders = () => ({
  'Accept': 'application/json',
  'Content-Type': 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
  'X-CSRF-TOKEN': getCsrfToken(),
})

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    status: 'idle',
    error: null,
    hasCheckedAuth: false,
  }),
  getters: {
    isAuthenticated: state => Boolean(state.user),
  },
  actions: {
    async fetchUser() {
      this.status = 'checking'
      this.error = null

      try {
        const response = await fetch('/me', {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })

        if (!response.ok) {
          this.user = null
          this.status = 'anonymous'
          this.hasCheckedAuth = true

          return
        }

        const data = await response.json()

        this.user = data.user
        this.status = this.user ? 'authenticated' : 'anonymous'
      }
      catch (error) {
        console.error('Unable to fetch current user', error)
        this.user = null
        this.status = 'anonymous'
      }
      finally {
        this.hasCheckedAuth = true
      }
    },
    async login(payload) {
      this.status = 'loading'
      this.error = null

      try {
        await refreshCsrfToken()

        const response = await fetch('/login', {
          method: 'POST',
          headers: jsonHeaders(),
          credentials: 'same-origin',
          body: JSON.stringify(payload),
        })

        if (!response.ok) {
          const errorBody = await response.json().catch(() => ({}))

          this.status = 'error'
          this.error = errorBody.message || 'Credenciales incorrectas.'

          throw new Error(this.error)
        }

        const data = await response.json()

        this.error = null
        this.user = data.user
        this.status = 'authenticated'
        this.hasCheckedAuth = true

        await refreshCsrfToken()

        return data.user
      }
      catch (error) {
        if (!this.error)
          this.error = error.message
        throw error
      }
    },
    async logout() {
      try {
        await refreshCsrfToken()

        await fetch('/logout', {
          method: 'POST',
          headers: jsonHeaders(),
          credentials: 'same-origin',
        })
      }
      finally {
        await refreshCsrfToken()
        this.user = null
        this.status = 'anonymous'
        this.error = null
        this.hasCheckedAuth = true
      }
    },
  },
})
