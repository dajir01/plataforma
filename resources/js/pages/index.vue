<script setup>
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const empresa = computed(() => authStore.user)
const isLoading = computed(() => !authStore.hasCheckedAuth || authStore.status === 'checking')

const bienvenida = computed(() => empresa.value?.nombre_empresa
  ? `¡Bienvenido, ${empresa.value.nombre_empresa}!`
  : '¡Bienvenido a la Plataforma de Expositores FEXCO!')

const accesos = [
  {
    icon: 'tabler-users',
    title: 'Acreditación de Expositores',
    text: 'Acredita a los integrantes de tu empresa que participarán en la feria.',
    label: 'Ir a acreditaciones',
    to: { name: 'acreditaciones-list' },
  },
  {
    icon: 'tabler-file-text',
    title: 'Reglamentos de Expositor',
    text: 'Consulta y descarga los reglamentos oficiales de los eventos en los que participa tu empresa.',
    label: 'Ver reglamentos',
    to: { name: 'reglamentos-expositor' },
  },
  {
    icon: 'tabler-building',
    title: 'Información de mi empresa',
    text: 'Consulta la información registrada de tu empresa y los datos relacionados con tu participación.',
    label: 'Ver mi empresa',
    to: { name: 'empresa' },
  },
]
</script>

<template>
  <div>
    <!-- 👉 Bienvenida -->
    <VCard class="mb-6">
      <VCardText>
        <h4 class="text-h4 mb-1">
          {{ bienvenida }}
        </h4>
        <p class="text-body-1 mb-2">
          Todo lo que necesitas para gestionar tu participación en un solo lugar.
        </p>
        <p class="text-body-2 mb-0">
          Desde esta plataforma podrás acreditar a los integrantes de tu empresa, consultar la información de tu
          empresa y acceder a documentos y recursos importantes para tu participación en FEXCO.
        </p>
      </VCardText>
    </VCard>

    <!-- 👉 Estado de carga / empresa autenticada -->
    <VAlert
      v-if="isLoading"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      Cargando información...
    </VAlert>

    <VAlert
      v-else-if="!empresa"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      No fue posible cargar la información de la empresa.
    </VAlert>

    <!-- 👉 Accesos rápidos -->
    <h5 class="text-h5 mb-4">
      Accesos rápidos
    </h5>

    <VRow>
      <VCol
        v-for="acceso in accesos"
        :key="acceso.title"
        cols="12"
        md="4"
      >
        <VCard class="h-100 d-flex flex-column">
          <VCardText class="grow">
            <VIcon
              :icon="acceso.icon"
              size="32"
              color="primary"
              class="mb-3"
            />
            <h6 class="text-h6 mb-1">
              {{ acceso.title }}
            </h6>
            <p class="text-body-2 mb-0">
              {{ acceso.text }}
            </p>
          </VCardText>
          <VCardText>
            <VBtn
              block
              variant="tonal"
              :to="acceso.to"
            >
              {{ acceso.label }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
