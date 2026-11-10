<script setup>
import { computed, onMounted, ref, watch } from 'vue'

definePage({ meta: { title: 'Reglamentos de Expositor' } })

const eventos = ref([])
const eventoSeleccionado = ref(null)
const reglamentos = ref([])
const cargandoEventos = ref(false)
const cargandoReglamentos = ref(false)
const error = ref('')
let solicitudReglamentos = 0

const eventoActual = computed(() => eventos.value.find(evento => evento.id_feria === eventoSeleccionado.value))

const obtenerJson = async url => {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })

  let payload
  try {
    payload = await response.json()
  } catch {
    throw new Error('El servidor devolvió una respuesta no válida.')
  }

  if (!response.ok || !payload.success)
    throw new Error(payload.message || 'No se pudo cargar la información de reglamentos.')

  return payload
}

const cargarEventos = async () => {
  cargandoEventos.value = true
  error.value = ''

  try {
    const payload = await obtenerJson('/reglamentos-expositor/eventos')

    eventos.value = payload.data || []
    eventoSeleccionado.value = eventos.value[0]?.id_feria ?? null
  } catch (exception) {
    error.value = exception.message
  } finally {
    cargandoEventos.value = false
  }
}

const cargarReglamentos = async idFeria => {
  const solicitud = ++solicitudReglamentos

  if (!idFeria) {
    reglamentos.value = []
    cargandoReglamentos.value = false

    return
  }

  cargandoReglamentos.value = true
  error.value = ''

  try {
    const payload = await obtenerJson(`/reglamentos-expositor/eventos/${idFeria}`)

    if (solicitud === solicitudReglamentos)
      reglamentos.value = payload.data || []
  } catch (exception) {
    if (solicitud === solicitudReglamentos) {
      reglamentos.value = []
      error.value = exception.message
    }
  } finally {
    if (solicitud === solicitudReglamentos)
      cargandoReglamentos.value = false
  }
}

const fechaFormato = fecha => {
  if (!fecha)
    return 'Fecha no definida'

  const valor = new Date(`${fecha}T00:00:00`)
  if (Number.isNaN(valor.getTime()))
    return fecha

  return new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium' }).format(valor)
}

watch(eventoSeleccionado, cargarReglamentos)
onMounted(cargarEventos)
</script>

<template>
  <section>
    <VCard class="mb-6">
      <VCardText class="d-flex align-center flex-wrap gap-4">
        <VAvatar
          color="primary"
          variant="tonal"
          size="52"
          rounded
        >
          <VIcon
            icon="tabler-file-text"
            size="28"
          />
        </VAvatar>
        <div class="grow">
          <h4 class="text-h5 mb-1">
            Reglamentos de Expositor
          </h4>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Consulta los documentos oficiales publicados para los eventos en los que tu empresa tiene contrato.
          </p>
        </div>
        <VSelect
          v-model="eventoSeleccionado"
          :items="eventos"
          item-title="nombre_feria"
          item-value="id_feria"
          label="Evento"
          :loading="cargandoEventos"
          :disabled="cargandoEventos || eventos.length === 0"
          hide-details
          class="reglamento-evento-select"
        />
      </VCardText>
    </VCard>

    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-5"
    >
      {{ error }}
    </VAlert>

    <VAlert
      v-if="!cargandoEventos && !eventos.length && !error"
      type="info"
      variant="tonal"
    >
      No hay eventos próximos asociados a un contrato de tu empresa.
    </VAlert>

    <template v-if="eventoActual">
      <div class="d-flex align-center flex-wrap justify-space-between gap-3 mb-4">
        <div>
          <h5 class="text-h5 mb-1">
            {{ eventoActual.nombre_feria }}
          </h5>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Evento: {{ fechaFormato(eventoActual.fecha_inicio) }} – {{ fechaFormato(eventoActual.fecha_fin) }}
          </p>
        </div>
        <VChip
          color="primary"
          variant="tonal"
          prepend-icon="tabler-file-check"
        >
          {{ reglamentos.length }} {{ reglamentos.length === 1 ? 'reglamento' : 'reglamentos' }}
        </VChip>
      </div>

      <VCard
        v-if="cargandoReglamentos"
        class="pa-5"
      >
        <VProgressCircular
          indeterminate
          size="20"
          class="me-3"
        />
        Cargando reglamentos...
      </VCard>

      <VAlert
        v-else-if="!reglamentos.length && !error"
        type="info"
        variant="tonal"
      >
        Todavía no se han publicado reglamentos para este evento.
      </VAlert>

      <VRow v-else>
        <VCol
          v-for="reglamento in reglamentos"
          :key="reglamento.id_reglamento"
          cols="12"
          md="6"
          lg="4"
        >
          <VCard class="h-100 d-flex flex-column">
            <VCardText class="grow">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                class="mb-4"
              >
                <VIcon icon="tabler-file-type-pdf" />
              </VAvatar>
              <h6 class="text-h6 mb-2">
                {{ reglamento.nombre_reglamento || reglamento.nombre_archivo }}
              </h6>
              <p
                v-if="reglamento.descripcion"
                class="text-body-2 text-medium-emphasis mb-3"
              >
                {{ reglamento.descripcion }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ reglamento.nombre_archivo }}
              </p>
            </VCardText>
            <VCardText class="pt-0">
              <VBtn
                block
                variant="tonal"
                color="primary"
                :href="`/reglamentos-expositor/${reglamento.id_reglamento}/descargar`"
                prepend-icon="tabler-download"
              >
                Descargar reglamento
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </section>
</template>

<style scoped>
.reglamento-evento-select {
  inline-size: min(100%, 22rem);
}
</style>
