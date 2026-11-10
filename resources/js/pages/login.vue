<script setup>
import AuthProvider from '@/views/pages/authentication/AuthProvider.vue'
import { useAuthStore } from '@/stores/auth'
import authV1BottomShape from '@images/svg/auth-v1-bottom-shape.svg?raw'
import authV1TopShape from '@images/svg/auth-v1-top-shape.svg?raw'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    public: true,
    guestOnly: true,
  },
})

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const form = ref({
  identificador: '',
  password: '',
  remember: true,
})

const isSubmitting = ref(false)
const isPasswordVisible = ref(false)
const mostrarAvisoPrivacidad = ref(false)
const errorMessage = computed(() => authStore.error)

const submit = async () => {
  if (isSubmitting.value)
    return

  isSubmitting.value = true
  try {
    await authStore.login({
      identificador: form.value.identificador,
      password: form.value.password,
      remember: form.value.remember,
    })

    const redirectTo = typeof route.query.redirect === 'string' ? route.query.redirect : '/'

    await router.replace(redirectTo)
  }
  catch (error) {
    console.error('Login failed', error)
  }
  finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="auth-wrapper d-flex align-center justify-center pa-4">
    <div class="position-relative my-sm-16">
      <!-- 👉 Top shape -->
      <VNodeRenderer
        :nodes="h('div', { innerHTML: authV1TopShape })"
        class="text-primary auth-v1-top-shape d-none d-sm-block"
      />

      <!-- 👉 Bottom shape -->
      <VNodeRenderer
        :nodes="h('div', { innerHTML: authV1BottomShape })"
        class="text-primary auth-v1-bottom-shape d-none d-sm-block"
      />

      <!-- 👉 Auth Card -->
      <VCard
        class="auth-card"
        max-width="460"
        :class="$vuetify.display.smAndUp ? 'pa-6' : 'pa-0'"
      >
        <VCardItem class="justify-center">
          <VCardTitle>
            <RouterLink to="/">
              <div class="app-logo">
                <VNodeRenderer :nodes="themeConfig.app.logo" />
                <h1 class="app-logo-title">
                  {{ themeConfig.app.title }}
                </h1>
              </div>
            </RouterLink>
          </VCardTitle>
        </VCardItem>

        <VCardText>
          <h4 class="text-h4 mb-1">
            Bienvenidos a la Plataforma <span class="text-capitalize">{{ themeConfig.app.title }}</span>! 👋🏻
          </h4>
          <p class="mb-0">
            Por favor, inicie sesión para continuar.
          </p>
        </VCardText>

        <VCardText>
          <VAlert
            v-if="errorMessage"
            type="error"
            density="compact"
            class="mb-4"
            closable
            @click:close="authStore.error = null"
          >
            {{ errorMessage }}
          </VAlert>

          <VForm @submit.prevent="submit">
            <VRow>
              <!-- identificador -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.identificador"
                  autofocus
                  label="Nit o Usuario"
                  type="text"
                  placeholder="Nit o Usuario"
                  autocomplete="username"
                />
              </VCol>

              <!-- password -->
              <VCol cols="12">
                <AppTextField
                  v-model="form.password"
                  label="Contraseña"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="current-password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />

                <div class="d-flex align-center justify-space-between flex-wrap mt-1 mb-4">
                  <VCheckbox
                    v-model="form.remember"
                    label="Mantener la sesión iniciada"
                  />
                </div>

                <!-- login button -->
                <VBtn
                  block
                  type="submit"
                  :loading="isSubmitting"
                  :disabled="isSubmitting"
                >
                  {{ isSubmitting ? 'Iniciando sesión...' : 'Ingresar' }}
                </VBtn>

                <p class="text-center text-body-2 mt-4 mb-0">
                  Al iniciar sesión, aceptas nuestro
                  <a
                    href="javascript:void(0)"
                    @click="mostrarAvisoPrivacidad = true"
                  >
                    Aviso de Privacidad
                  </a>.
                </p>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </div>

    <PrivacyNoticeDialog v-model:is-dialog-visible="mostrarAvisoPrivacidad" />
  </div>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";
</style>
