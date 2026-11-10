<script setup>
import { computed, onMounted, ref } from 'vue'

definePage({ meta: { title: 'Información de mi empresa' } })

const empresa = ref(null)
const participaciones = ref([])
const cargando = ref(true)
const error = ref('')

const secciones = computed(() => {
  if (!empresa.value)
    return []

  return [
    {
      titulo: 'Datos generales',
      icono: 'tabler-building',
      campos: [
        { etiqueta: 'Nombre o razón social', valor: empresa.value.nombre_empresa, columnas: 6 },
        { etiqueta: 'NIT', valor: empresa.value.nit, columnas: 3 },
        { etiqueta: 'Fecha de aniversario', valor: empresa.value.aniversario, columnas: 3 },
        { etiqueta: 'Tamaño de empresa', valor: empresa.value.categoria_nombre, columnas: 3 },
        { etiqueta: 'Actividad principal', valor: empresa.value.actividad_principal_nombre, columnas: 3 },
        { etiqueta: 'Rubro', valor: empresa.value.rubro_nombre, columnas: 2 },
        { etiqueta: 'Subrubro', valor: empresa.value.subrubro_nombre, columnas: 2 },
        { etiqueta: 'Otros rubros', valor: empresa.value.otro_rubro, columnas: 2 },
        { etiqueta: 'Productos o servicios', valor: empresa.value.desc_producto, multilinea: true },
      ],
    },
    {
      titulo: 'Contacto y ubicación',
      icono: 'tabler-map-pin',
      campos: [
        { etiqueta: 'Dirección', valor: empresa.value.direccion, columnas: 4 },
        { etiqueta: 'País', valor: empresa.value.pais_nombre, columnas: 2 },
        { etiqueta: 'Ciudad', valor: empresa.value.ciudad_nombre, columnas: 2 },
        { etiqueta: 'Teléfono', valor: empresa.value.telefono, columnas: 2 },
        { etiqueta: 'Fax', valor: empresa.value.fax, columnas: 2 },
        { etiqueta: 'Correo electrónico', valor: empresa.value.email, columnas: 6 },
        { etiqueta: 'Sitio web', valor: empresa.value.web, columnas: 6 },
      ],
    },
    {
      titulo: 'Información legal',
      icono: 'tabler-file-certificate',
      campos: [
        { etiqueta: 'Número de escritura', valor: empresa.value.nr_escritura, columnas: 2 },
        { etiqueta: 'Fecha de escritura', valor: empresa.value.fecha_nr_escritura, columnas: 2 },
        { etiqueta: 'Matrícula', valor: empresa.value.matricula, columnas: 2 },
        { etiqueta: 'Número de poder', valor: empresa.value.nr_poder, columnas: 2 },
        { etiqueta: 'Notaría', valor: empresa.value.nr_notaria || empresa.value.nr_notario, columnas: 2 },
        { etiqueta: 'Fecha de poder', valor: empresa.value.fecha_nr_poder, columnas: 2 },
        { etiqueta: 'Distrito', valor: empresa.value.distrito, columnas: 2 },
      ],
    },
    {
      titulo: 'Representante legal',
      icono: 'tabler-user-shield',
      campos: [
        { etiqueta: 'Nombre', valor: empresa.value.nombre_gerente, columnas: 4 },
        { etiqueta: 'Documento de identidad', valor: empresa.value.ci_gerente, columnas: 2 },
        { etiqueta: 'Expedido en', valor: empresa.value.exp_ci_gerente, columnas: 2 },
        { etiqueta: 'Cargo', valor: empresa.value.cargo_gerente, columnas: 2 },
        { etiqueta: 'Teléfono', valor: empresa.value.fono_gerente, columnas: 2 },
      ],
    },
    {
      titulo: 'Persona de contacto',
      icono: 'tabler-user',
      campos: [
        { etiqueta: 'Nombre', valor: empresa.value.nombre_responsable, columnas: 4 },
        { etiqueta: 'Teléfono', valor: empresa.value.telefono_responsable, columnas: 4 },
        { etiqueta: 'Correo de representación', valor: empresa.value.email_representante, columnas: 4 },
      ],
    },
  ]
})

const mostrarValor = valor => {
  if (valor === null || valor === undefined || String(valor).trim() === '')
    return 'No registrado'

  return String(valor)
}

const fechaFormato = fecha => {
  if (!fecha)
    return 'Fecha no definida'

  const valor = new Date(`${fecha}T00:00:00`)
  if (Number.isNaN(valor.getTime()))
    return fecha

  return new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium' }).format(valor)
}

const cargarInformacion = async () => {
  cargando.value = true
  error.value = ''

  try {
    const response = await fetch('/empresa/informacion', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })

    let payload
    try {
      payload = await response.json()
    }
    catch {
      throw new Error('El servidor devolvió una respuesta no válida.')
    }

    if (!response.ok || !payload.success)
      throw new Error(payload.message || 'No se pudo cargar la información de la empresa.')

    empresa.value = payload.data.empresa
    participaciones.value = payload.data.participaciones || []
  }
  catch (exception) {
    error.value = exception.message
  }
  finally {
    cargando.value = false
  }
}

onMounted(cargarInformacion)
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
            icon="tabler-building"
            size="28"
          />
        </VAvatar>
        <div class="grow">
          <h4 class="text-h5 mb-1">
            Información de mi empresa
          </h4>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Consulta la información registrada y los datos de participación de tu empresa.
          </p>
        </div>
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

    <VCard
      v-if="cargando"
      class="pa-5 mb-6"
    >
      <VProgressCircular
        indeterminate
        size="20"
        class="me-3"
      />
      Cargando información de la empresa...
    </VCard>

    <template v-else-if="empresa">
      <VAlert
        type="info"
        variant="tonal"
        class="mb-6"
        icon="tabler-info-circle"
      >
        Esta información es solo de consulta. Para solicitar una actualización, comunícate con el equipo de FEXCO.
      </VAlert>

      <VRow>
        <VCol
          v-for="seccion in secciones"
          :key="seccion.titulo"
          cols="12"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                >
                  <VIcon :icon="seccion.icono" />
                </VAvatar>
              </template>
              <VCardTitle>
                {{ seccion.titulo }}
              </VCardTitle>
            </VCardItem>
            <VCardText>
              <VRow>
                <VCol
                  v-for="campo in seccion.campos"
                  :key="campo.etiqueta"
                  cols="12"
                  :md="campo.columnas || (campo.multilinea ? 12 : 6)"
                >
                  <VTextarea
                    v-if="campo.multilinea"
                    :model-value="mostrarValor(campo.valor)"
                    :label="campo.etiqueta"
                    readonly
                    rows="2"
                    auto-grow
                    hide-details
                  />
                  <VTextField
                    v-else
                    :model-value="mostrarValor(campo.valor)"
                    :label="campo.etiqueta"
                    readonly
                    hide-details
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <div class="d-flex align-center flex-wrap justify-space-between gap-3 mt-8 mb-4">
        <div>
          <h5 class="text-h5 mb-1">
            Participación en eventos
          </h5>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Contratos registrados para {{ empresa.nombre_empresa || 'tu empresa' }}.
          </p>
        </div>
        <VChip
          color="primary"
          variant="tonal"
          prepend-icon="tabler-calendar-event"
        >
          {{ participaciones.length }} {{ participaciones.length === 1 ? 'evento' : 'eventos' }}
        </VChip>
      </div>

      <VAlert
        v-if="!participaciones.length"
        type="info"
        variant="tonal"
      >
        No hay contratos de participación disponibles para mostrar.
      </VAlert>

      <VRow v-else>
        <VCol
          v-for="(participacion, indice) in participaciones"
          :key="`${participacion.codigo_contrato}-${indice}`"
          cols="12"
          md="6"
          lg="4"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>
                {{ participacion.evento }}
              </VCardTitle>
              <VCardSubtitle>
                Contrato {{ participacion.codigo_contrato }}
              </VCardSubtitle>
            </VCardItem>
            <VCardText>
              <div class="text-body-2 mb-2">
                <strong>Fechas:</strong>
                {{ fechaFormato(participacion.fecha_inicio) }} –
                {{ fechaFormato(participacion.fecha_fin) }}
              </div>
              <div class="text-body-2 mb-2">
                <strong>Stands:</strong>
                {{ participacion.stands.length ? participacion.stands.join(', ') : 'No registrados' }}
                <span v-if="participacion.cantidad_stands">({{ participacion.cantidad_stands }} en total)</span>
              </div>
              <div class="text-body-2 mb-2">
                <strong>Superficie:</strong>
                {{ mostrarValor(participacion.metraje_total) }} m²
              </div>
              <div class="text-body-2">
                <strong>Credenciales:</strong>
                {{ mostrarValor(participacion.cantidad_credenciales) }}
                <span v-if="participacion.tipo_credenciales">({{ participacion.tipo_credenciales }})</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </section>
</template>
