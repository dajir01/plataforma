<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { refreshCsrfToken, useAuthStore } from '@/stores/auth'

definePage({ meta: { title: 'Acreditaciones' } })

const auth = useAuthStore()
const eventos = ref([])
const eventoSeleccionado = ref(null)
const tipos = ref([])
const loadingEventos = ref(false)
const loadingResumen = ref(false)
const error = ref('')
const mensaje = ref('')
const mostrarMensaje = ref(false)
const busqueda = ref({})
const paginasTipo = ref({})
const tiposAbiertos = ref([])
const elementosPorPagina = ref(10)
const dialogAcreditar = ref(false)
const tipoSeleccionado = ref(null)
const asignacionSeleccionada = ref('')
const credencialesSeleccionadas = ref([])
const dialogConflicto = ref(false)
const expositorConflicto = ref(null)
const guardando = ref(false)
const comprimiendoFoto = ref(false)
const errorFormulario = ref('')
const erroresCampos = ref({})
const buscandoCi = ref(false)
const resultadoCi = ref(null)
const camposHabilitados = ref(false)
const ciAcreditadoEnEvento = ref(false)
const archivoFoto = ref(null)
const previewFoto = ref('')
const inputFoto = ref(null)
const tamanoMaximoFoto = 2 * 1024 * 1024
let actualizacionTimer = null
let solicitudResumen = 0

const codigosPais = [
  { title: 'Bolivia (+591)', value: '591' },
  { title: 'Argentina (+54)', value: '54' },
  { title: 'Brasil (+55)', value: '55' },
  { title: 'Chile (+56)', value: '56' },
  { title: 'Colombia (+57)', value: '57' },
  { title: 'Perú (+51)', value: '51' },
  { title: 'México (+52)', value: '52' },
  { title: 'España (+34)', value: '34' },
  { title: 'Estados Unidos (+1)', value: '1' },
]

const formulario = ref({
  idExpositor: null,
  ci: '',
  tipoDocumento: '',
  nombre: '',
  email: '',
  celular: '',
  codigoPais: '591',
  numeroCelular: '',
  foto: null,
})

const numeroCelularNormalizado = computed(() => String(formulario.value.numeroCelular || '').replace(/\D/g, ''))

const celularValido = computed(() => {
  const numero = numeroCelularNormalizado.value
  if (!numero) return true
  if (formulario.value.codigoPais === '591') return /^\d{8}$/.test(numero)

  return /^\d{7,14}$/.test(numero)
})

const celularFormateado = computed(() => `+${formulario.value.codigoPais}${numeroCelularNormalizado.value}`)

const empresa = computed(() => auth.user)
const eventoActual = computed(() => eventos.value.find(evento => evento.id_feria === eventoSeleccionado.value))

const asignacionesDisponibles = computed(() => (tipoSeleccionado.value?.registros || [])
  .filter(registro => Number(registro.credenciales_disponibles || 0) > 0)
  .map(registro => ({
    ...registro,
    clave: registro.id_contrato ? `contrato:${registro.id_contrato}` : `vinculacion:${registro.id_vinculacion}`,
    etiqueta: registro.id_contrato
      ? `Contrato ${registro.codigo_contrato} · ${registro.credenciales_disponibles} disponibles`
      : `${registro.codigo_contrato} · ${registro.credenciales_disponibles} disponibles`,
  })))

const tiposVisibles = computed(() => tipos.value.filter(tipo => tipo.registros?.length))
const sinEventos = computed(() => !loadingEventos.value && eventos.value.length === 0)
const sinCredenciales = computed(() => !loadingResumen.value && Boolean(eventoSeleccionado.value) && tiposVisibles.value.length === 0)
const hayCredencialesFisicas = computed(() => tipos.value.some(tipo => tipo.registros?.some(registro => String(registro.modalidad_credencial || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').startsWith('fis'))))
const hayCredencialesDigitales = computed(() => tipos.value.some(tipo => tipo.registros?.some(registro => String(registro.modalidad_credencial || '').toLowerCase().startsWith('dig'))))

const totales = computed(() => tipos.value.reduce((total, tipo) => ({
  asignadas: total.asignadas + Number(tipo.asignadas || 0),
  acreditadas: total.acreditadas + Number(tipo.utilizadas || 0),
  disponibles: total.disponibles + Number(tipo.disponibles || 0),
}), { asignadas: 0, acreditadas: 0, disponibles: 0 }))

const obtener = async (url, options = {}) => {
  const headers = { Accept: 'application/json', ...(options.headers || {}) }
  const method = (options.method || 'GET').toUpperCase()
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    const token = await refreshCsrfToken()
    if (!token)
      throw new Error('No se pudo renovar el token de seguridad. Recargue la página e inténtelo de nuevo.')
    headers['X-CSRF-TOKEN'] = token
    if (options.body instanceof FormData)
      options.body.set('_token', token)
  }

  const response = await fetch(url, { ...options, headers, credentials: 'same-origin' })
  let payload
  try {
    payload = await response.json()
  } catch {
    throw new Error('El servidor devolvió una respuesta no válida.')
  }
  if (!response.ok) {
    const mensajeValidacion = Object.values(payload.errors || {}).flat().find(Boolean)
    const error = new Error(payload.message || mensajeValidacion || 'No se pudo cargar la información.')

    error.validationErrors = payload.errors || {}
    throw error
  }
  
  return payload
}

const cargarResumen = async (idFeria, mostrarCarga = true) => {
  const idSolicitud = ++solicitudResumen
  if (!idFeria) {
    tipos.value = []
    loadingResumen.value = false

    return
  }
  if (mostrarCarga) loadingResumen.value = true
  if (mostrarCarga) error.value = ''
  try {
    const payload = await obtener(`/acreditaciones/eventos/${idFeria}/resumen`)
    if (idSolicitud !== solicitudResumen) return

    error.value = ''
    tipos.value = payload?.data?.tipos || []
    if (mostrarCarga) {
      paginasTipo.value = {}
      tiposAbiertos.value = tiposVisibles.value.map(tipo => tipo.tipo)
      credencialesSeleccionadas.value = []
    }
  } catch (exception) {
    if (idSolicitud === solicitudResumen) {
      if (mostrarCarga)
        tipos.value = []
      error.value = exception.message
    }
  } finally {
    if (idSolicitud === solicitudResumen && mostrarCarga)
      loadingResumen.value = false
  }
}

const cargarEventos = async () => {
  loadingEventos.value = true
  try {
    const payload = await obtener('/acreditaciones/eventos')

    eventos.value = payload?.data || []
    eventoSeleccionado.value = eventos.value[0]?.id_feria || null
  } catch (exception) {
    error.value = exception.message
  } finally {
    loadingEventos.value = false
  }
}

const tipoAbierto = tipo => tiposAbiertos.value.includes(tipo)
const tipoTieneDisponibles = tipo => tipo.registros?.some(registro => Number(registro.credenciales_disponibles ?? tipo.disponibles ?? 0) > 0) ?? false

const alternarTipo = tipo => {
  tiposAbiertos.value = tipoAbierto(tipo) ? tiposAbiertos.value.filter(item => item !== tipo) : [...tiposAbiertos.value, tipo]
}

const acreditadosFiltrados = tipo => {
  const termino = String(busqueda.value[tipo.tipo] || '').trim().toLowerCase()
  const lista = tipo.registros.flatMap(registro => registro.acreditados || [])
  
  return termino ? lista.filter(item => [item.nombre, item.ci].some(valor => String(valor || '').toLowerCase().includes(termino))) : lista
}

const acreditadosPaginados = tipo => {
  const inicio = ((paginasTipo.value[tipo.tipo] || 1) - 1) * elementosPorPagina.value
  
  return acreditadosFiltrados(tipo).slice(inicio, inicio + elementosPorPagina.value)
}

const totalPaginas = tipo => Math.max(1, Math.ceil(acreditadosFiltrados(tipo).length / elementosPorPagina.value))
const tipoColor = tipo => ({ Expositor: 'success', Oficial: 'info', Prensa: 'secondary', Servicios: 'warning', Negocios: 'primary' }[tipo] || 'primary')

const urlFoto = foto => {
  if (!foto)
    return ''

  if (/^https?:\/\//i.test(foto))
    return foto

  const filename = String(foto).split('/').pop()
  if (!/^acreditado_[a-f0-9-]+\.(?:jpg|jpeg|png)$/i.test(filename))
    return ''

  const url = `/acreditaciones/fotos/${encodeURIComponent(filename)}`

  return eventoSeleccionado.value
    ? `${url}?id_feria=${encodeURIComponent(eventoSeleccionado.value)}`
    : url
}

const esDigital = acreditado => acreditado.modalidad_credencial === 'Digital'
const seleccionadosDeTipo = tipo => acreditadosFiltrados(tipo).filter(acreditado => credencialesSeleccionadas.value.includes(acreditado.id_credencial))

const todosSeleccionados = tipo => {
  const digitales = acreditadosFiltrados(tipo).filter(esDigital)

  return digitales.length > 0 && digitales.every(acreditado => credencialesSeleccionadas.value.includes(acreditado.id_credencial))
}

const seleccionParcial = tipo => {
  const digitales = acreditadosFiltrados(tipo).filter(esDigital)
  const seleccionados = digitales.filter(acreditado => credencialesSeleccionadas.value.includes(acreditado.id_credencial)).length

  return seleccionados > 0 && seleccionados < digitales.length
}

const alternarSeleccion = (acreditado, seleccionado) => {
  if (!esDigital(acreditado)) return

  credencialesSeleccionadas.value = seleccionado
    ? [...new Set([...credencialesSeleccionadas.value, acreditado.id_credencial])]
    : credencialesSeleccionadas.value.filter(id => id !== acreditado.id_credencial)
}

const alternarTodos = (tipo, seleccionado) => {
  const ids = acreditadosFiltrados(tipo).filter(esDigital).map(acreditado => acreditado.id_credencial)

  credencialesSeleccionadas.value = seleccionado
    ? [...new Set([...credencialesSeleccionadas.value, ...ids])]
    : credencialesSeleccionadas.value.filter(id => !ids.includes(id))
}

const descargarIndividual = async idCredencial => {
  try {
    const response = await fetch(`/acreditaciones/credenciales/${idCredencial}/descargar`, {
      headers: { Accept: 'image/png', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })

    const contentType = response.headers.get('content-type') || ''
    if (!response.ok || response.redirected || !contentType.includes('image/png')) {
      throw new Error('La sesión expiró o el servidor no devolvió una imagen PNG válida.')
    }

    const bytesRespuesta = new Uint8Array(await response.arrayBuffer())
    const firmaPng = [137, 80, 78, 71, 13, 10, 26, 10]
    let inicioPng = -1
    for (let indice = 0; indice <= bytesRespuesta.length - firmaPng.length; indice++) {
      if (firmaPng.every((byte, offset) => bytesRespuesta[indice + offset] === byte)) {
        inicioPng = indice
        break
      }
    }
    if (inicioPng < 0) throw new Error('El servidor no devolvió una imagen PNG válida.')

    const archivo = new Blob([bytesRespuesta.slice(inicioPng)], { type: 'image/png' })
    const urlBlob = URL.createObjectURL(archivo)
    const enlace = document.createElement('a')

    enlace.href = urlBlob
    enlace.download = `credencial-${idCredencial}.png`
    document.body.appendChild(enlace)
    enlace.click()
    enlace.remove()
    setTimeout(() => URL.revokeObjectURL(urlBlob), 1000)
  } catch (exception) {
    error.value = exception.message
  }
}

const descargarSeleccionadas = async tipo => {
  const seleccionadas = seleccionadosDeTipo(tipo).map(acreditado => acreditado.id_credencial)

  if (!seleccionadas.length) return

  try {
    const csrfToken = await refreshCsrfToken()
    if (!csrfToken)
      throw new Error('No se pudo renovar el token de seguridad. Recargue la página e inténtelo de nuevo.')

    const response = await fetch('/acreditaciones/credenciales/descargar', {
      method: 'POST',
      headers: {
        Accept: 'application/zip',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      credentials: 'same-origin',
      body: JSON.stringify({ credenciales: seleccionadas, archivo: true, _token: csrfToken }),
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(payload.message || 'No se pudo descargar el archivo ZIP.')
    }

    const bytesRespuesta = new Uint8Array(await response.arrayBuffer())
    const firmaZip = [0x50, 0x4b, 0x03, 0x04]
    let inicioZip = -1
    for (let indice = 0; indice <= bytesRespuesta.length - firmaZip.length; indice++) {
      if (firmaZip.every((byte, offset) => bytesRespuesta[indice + offset] === byte)) {
        inicioZip = indice
        break
      }
    }
    if (inicioZip < 0) throw new Error('El servidor no devolvió un archivo ZIP válido.')

    const archivo = new Blob([bytesRespuesta.slice(inicioZip)], { type: 'application/zip' })
    const urlBlob = URL.createObjectURL(archivo)
    const enlace = document.createElement('a')

    enlace.href = urlBlob
    enlace.download = 'credenciales-digitales.zip'
    document.body.appendChild(enlace)
    enlace.click()
    enlace.remove()
    setTimeout(() => URL.revokeObjectURL(urlBlob), 1000)

    credencialesSeleccionadas.value = credencialesSeleccionadas.value.filter(id => !seleccionadas.includes(id))
  } catch (exception) {
    error.value = exception.message
  }
}

const enviarSeleccionadas = async tipo => {
  const seleccionadas = seleccionadosDeTipo(tipo).map(acreditado => acreditado.id_credencial)
  if (!seleccionadas.length) return

  error.value = ''
  mensaje.value = ''
  try {
    const payload = await obtener('/acreditaciones/credenciales/enviar', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
      body: JSON.stringify({ credenciales: seleccionadas }),
    })

    mensaje.value = payload.message || 'Las credenciales fueron enviadas correctamente.'
    mostrarMensaje.value = true

    const enviadas = payload.credenciales_enviadas || []

    credencialesSeleccionadas.value = credencialesSeleccionadas.value.filter(id => !enviadas.includes(id))
  } catch (exception) {
    error.value = exception.message
  }
}

const limpiarFormulario = () => {
  if (previewFoto.value.startsWith('blob:'))
    URL.revokeObjectURL(previewFoto.value)
  asignacionSeleccionada.value = ''
  formulario.value = { idExpositor: null, ci: '', tipoDocumento: '', nombre: '', email: '', celular: '', codigoPais: '591', numeroCelular: '', foto: null }
  resultadoCi.value = null
  camposHabilitados.value = false
  ciAcreditadoEnEvento.value = false
  archivoFoto.value = null
  previewFoto.value = ''
  errorFormulario.value = ''
  erroresCampos.value = {}
  dialogConflicto.value = false
  expositorConflicto.value = null
}

const limpiarErrorCampo = campo => {
  if (!erroresCampos.value[campo])
    return

  const errores = { ...erroresCampos.value }

  delete errores[campo]
  erroresCampos.value = errores
  if (Object.keys(errores).length === 0)
    errorFormulario.value = ''
}

const mensajeErrorCampo = campo => {
  const error = erroresCampos.value[campo]

  return Array.isArray(error) ? error[0] : error
}

const abrirAcreditar = tipo => {
  if (!tipoTieneDisponibles(tipo)) return

  tipoSeleccionado.value = tipo
  limpiarFormulario()

  const primeraAsignacionDisponible = tipo.registros.find(registro => Number(registro.credenciales_disponibles || 0) > 0)

  asignacionSeleccionada.value = primeraAsignacionDisponible
    ? primeraAsignacionDisponible.id_contrato
      ? `contrato:${primeraAsignacionDisponible.id_contrato}`
      : `vinculacion:${primeraAsignacionDisponible.id_vinculacion}`
    : ''
  dialogAcreditar.value = true
}

const cerrarConflicto = () => {
  dialogConflicto.value = false
  expositorConflicto.value = null
}

const buscarPorCi = async () => {
  const ci = formulario.value.ci.trim()
  if (!ci) return
  resultadoCi.value = null
  buscandoCi.value = true
  try {
    const payload = await obtener(`/acreditaciones/expositores/buscar?ci=${encodeURIComponent(ci)}&id_feria=${eventoSeleccionado.value}`)
    const expositor = payload?.data
    if (expositor) {
      if (previewFoto.value.startsWith('blob:'))
        URL.revokeObjectURL(previewFoto.value)
      formulario.value = { ...formulario.value, idExpositor: expositor.id_expositor, ci: expositor.ci || ci, tipoDocumento: expositor.tipo_documento || '', nombre: expositor.nombre || '', email: expositor.email || '', celular: expositor.celular || '', numeroCelular: String(expositor.celular || '').replace(/\D/g, '').replace(/^591/, ''), foto: expositor.foto || null }
      previewFoto.value = urlFoto(expositor.foto)
      ciAcreditadoEnEvento.value = Boolean(expositor.credencial_evento)
      if (expositor.credencial_evento) {
        expositorConflicto.value = { ...expositor, credencial: expositor.credencial_evento }
        dialogAcreditar.value = false
        dialogConflicto.value = true
      } else {
        resultadoCi.value = { type: 'success', text: 'Datos encontrados. Puede actualizar la información.' }
      }
    } else {
      resultadoCi.value = { type: 'warning', text: 'Datos no encontrados. Complete la información del nuevo expositor.' }
    }
    camposHabilitados.value = true
  } catch (exception) {
    resultadoCi.value = { type: 'error', text: exception.message }
  } finally {
    buscandoCi.value = false
  }
}

const comprimirFoto = async archivo => {
  const imagen = await createImageBitmap(archivo)
  const canvas = document.createElement('canvas')
  const contexto = canvas.getContext('2d')
  if (!contexto) {
    imagen.close()
    throw new Error('No se pudo procesar la fotografía.')
  }

  try {
    let escala = Math.min(1, 2560 / Math.max(imagen.width, imagen.height))
    let calidad = 0.88

    for (let intento = 0; intento < 24; intento++) {
      canvas.width = Math.max(1, Math.round(imagen.width * escala))
      canvas.height = Math.max(1, Math.round(imagen.height * escala))
      contexto.fillStyle = '#fff'
      contexto.fillRect(0, 0, canvas.width, canvas.height)
      contexto.drawImage(imagen, 0, 0, canvas.width, canvas.height)

      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', calidad))
      if (!blob)
        throw new Error('No se pudo procesar la fotografía.')

      if (blob.size <= tamanoMaximoFoto) {
        const nombre = archivo.name.replace(/\.[^.]+$/, '') || 'acreditado'

        return new File([blob], `${nombre}.jpg`, { type: 'image/jpeg', lastModified: Date.now() })
      }

      if (calidad > 0.55)
        calidad = Math.max(0.55, calidad - 0.1)
      else {
        escala *= 0.85
        calidad = 0.82
      }
    }

    throw new Error('No se pudo reducir la fotografía a 2 MB. Elija una imagen más pequeña.')
  } finally {
    imagen.close()
  }
}

const seleccionarFoto = async event => {
  const archivo = event.target.files?.[0]
  if (!archivo) return
  event.target.value = ''

  if (!['image/jpeg', 'image/png'].includes(archivo.type)) {
    erroresCampos.value = { ...erroresCampos.value, foto: 'Seleccione una imagen JPG o PNG.' }
    errorFormulario.value = 'Corrija el campo de fotografía marcado en rojo.'

    return
  }

  comprimiendoFoto.value = archivo.size > tamanoMaximoFoto
  limpiarErrorCampo('foto')
  try {
    const foto = comprimiendoFoto.value ? await comprimirFoto(archivo) : archivo
    if (previewFoto.value.startsWith('blob:'))
      URL.revokeObjectURL(previewFoto.value)
    archivoFoto.value = foto
    formulario.value.foto = foto
    previewFoto.value = URL.createObjectURL(foto)
  } catch (exception) {
    erroresCampos.value = { ...erroresCampos.value, foto: exception.message }
    errorFormulario.value = 'Corrija el campo de fotografía marcado en rojo.'
  } finally {
    comprimiendoFoto.value = false
  }
}

const guardarAcreditado = async () => {
  if (comprimiendoFoto.value || erroresCampos.value.foto) return

  const asignacion = asignacionesDisponibles.value.find(item => item.clave === asignacionSeleccionada.value)
  if (!asignacion) {
    errorFormulario.value = 'Seleccione una asignación con credenciales disponibles.'

    return
  }

  if (ciAcreditadoEnEvento.value) {
    errorFormulario.value = 'Este expositor ya está acreditado en este evento.'

    return
  }

  const errores = {}
  if (!formulario.value.ci.trim())
    errores.ci = 'Ingrese el carnet de identidad.'
  if (!formulario.value.tipoDocumento)
    errores.tipoDocumento = 'Seleccione el tipo de documento.'
  if (!formulario.value.nombre.trim())
    errores.nombre = 'Ingrese el nombre completo.'
  if (!celularValido.value)
    errores.celular = formulario.value.codigoPais === '591' ? 'Bolivia requiere 8 dígitos.' : 'Ingrese entre 7 y 14 dígitos.'
  if (!formulario.value.foto && !archivoFoto.value)
    errores.foto = 'Cargue una fotografía para continuar.'

  erroresCampos.value = errores
  if (Object.keys(errores).length > 0) {
    errorFormulario.value = 'Complete o corrija los campos marcados en rojo.'

    return
  }

  guardando.value = true
  errorFormulario.value = ''
  try {
    const body = new FormData()

    if (asignacion.id_contrato)
      body.append('id_contrato', asignacion.id_contrato)
    if (asignacion.id_vinculacion)
      body.append('id_vinculacion', asignacion.id_vinculacion)
    body.append('tipo_credencial', tipoSeleccionado.value.tipo)
    body.append('ci', formulario.value.ci.trim())
    body.append('tipo_documento', formulario.value.tipoDocumento)
    body.append('nombre', formulario.value.nombre.trim())
    body.append('email', formulario.value.email.trim())
    body.append('celular', numeroCelularNormalizado.value ? celularFormateado.value : formulario.value.celular.trim())
    if (archivoFoto.value) body.append('foto', archivoFoto.value)
    await obtener(`/acreditaciones/eventos/${eventoSeleccionado.value}/acreditados`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body })
    dialogAcreditar.value = false
    await cargarResumen(eventoSeleccionado.value)
  } catch (exception) {
    const erroresServidor = exception.validationErrors || {}

    const camposValidables = [
      { respuesta: 'ci', campo: 'ci' },
      { respuesta: 'tipo_documento', campo: 'tipoDocumento' },
      { respuesta: 'nombre', campo: 'nombre' },
      { respuesta: 'email', campo: 'email' },
      { respuesta: 'celular', campo: 'celular' },
      { respuesta: 'foto', campo: 'foto' },
    ]

    const erroresDeCampos = Object.fromEntries(
      camposValidables
        .filter(({ respuesta }) => erroresServidor[respuesta])
        .map(({ respuesta, campo }) => [campo, erroresServidor[respuesta]]),
    )

    erroresCampos.value = erroresDeCampos
    errorFormulario.value = Object.keys(erroresDeCampos).length > 0
      ? 'Complete o corrija los campos marcados en rojo.'
      : exception.message
  } finally {
    guardando.value = false
  }
}

watch(eventoSeleccionado, idFeria => cargarResumen(idFeria))
onMounted(async () => {
  await cargarEventos()
  actualizacionTimer = window.setInterval(() => {
    if (eventoSeleccionado.value && !loadingResumen.value && !document.hidden)
      cargarResumen(eventoSeleccionado.value, false)
  }, 30000)
})
onBeforeUnmount(() => {
  if (actualizacionTimer)
    window.clearInterval(actualizacionTimer)
  if (previewFoto.value.startsWith('blob:'))
    URL.revokeObjectURL(previewFoto.value)
})
</script>

<template>
  <section>
    <VProgressLinear
      v-if="loadingEventos"
      indeterminate
      color="primary"
      class="mb-4"
    />
    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ error }}
    </VAlert>
    <VAlert
      v-if="sinEventos"
      type="info"
      variant="tonal"
    >
      No existen eventos disponibles para su empresa.
    </VAlert>
    <template v-if="eventoSeleccionado">
      <VCard class="mb-5">
        <VCardText>
          <VRow align="center">
            <VCol
              cols="12"
              md="7"
            >
              <div class="d-flex align-center gap-3">
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
                </VAvatar><div>
                  <p class="text-h6 mb-1">
                    {{ empresa?.nombre_empresa }}
                  </p><VChip
                    color="primary"
                    size="small"
                    label
                  >
                    {{ eventoActual?.nombre_feria }}
                  </VChip><div class="d-flex flex-wrap gap-3 mt-3 text-body-2">
                    <span><strong>NIT:</strong> {{ empresa?.nit || 'No registrado' }}</span><span><strong>Usuario:</strong> {{ empresa?.usuario || 'No registrado' }}</span>
                  </div>
                </div>
                <div class="d-flex align-center flex-wrap gap-5 mt-4 ms-1">
                  <div class="text-center">
                    <p class="text-caption text-disabled mb-0">
                      Asignadas
                    </p><p class="text-h6 mb-0">
                      {{ totales.asignadas }}
                    </p>
                  </div><div class="text-center">
                    <p class="text-caption text-disabled mb-0">
                      Acreditadas
                    </p><p class="text-h6 text-primary mb-0">
                      {{ totales.acreditadas }}
                    </p>
                  </div><div class="text-center">
                    <p class="text-caption text-disabled mb-0">
                      Disponibles
                    </p><p class="text-h6 text-success mb-0">
                      {{ totales.disponibles }}
                    </p>
                  </div>
                </div>
              </div>
            </VCol>
            <VCol
              cols="12"
              md="5"
            >
              <VSelect
                v-model="eventoSeleccionado"
                :items="eventos"
                item-title="nombre_feria"
                item-value="id_feria"
                label="Evento"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
      <div
        v-if="hayCredencialesFisicas || hayCredencialesDigitales"
        class="mb-5"
      >
        <VAlert
          v-if="hayCredencialesFisicas"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-2"
        >
          Si sus credenciales son físicas, puede pasar por nuestras oficinas a recogerlas, coordinando previamente con el encargado.
        </VAlert>
        <VAlert
          v-if="hayCredencialesDigitales"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-0"
        >
          Si sus credenciales son digitales, puede descargarlas desde esta pantalla. También puede seleccionar todas y enviarlas por correo electrónico; revise su bandeja de entrada y la carpeta de Spam.
        </VAlert>
      </div>
      <h5 class="text-h5 mb-4">
        Tipos de credencial
      </h5>
      <VCard
        v-if="loadingResumen"
        class="pa-5 mb-4"
      >
        <VProgressCircular
          indeterminate
          size="20"
          class="me-3"
        />Cargando tipos de credenciales...
      </VCard>
      <VAlert
        v-else-if="sinCredenciales"
        type="info"
        variant="tonal"
      >
        No existen credenciales disponibles para este evento.
      </VAlert>
      <VCard
        v-for="tipo in tiposVisibles"
        v-else
        :key="tipo.tipo"
        class="mb-4"
      >
        <VCardText class="pa-4">
          <div class="d-flex align-center gap-3 flex-wrap">
            <VBtn
              icon
              size="small"
              variant="text"
              @click="alternarTipo(tipo.tipo)"
            >
              <VIcon :icon="tipoAbierto(tipo.tipo) ? 'tabler-chevron-up' : 'tabler-chevron-down'" />
            </VBtn><div class="grow">
              <div class="d-flex align-center gap-2 flex-wrap">
                <VChip
                  :color="tipoColor(tipo.tipo)"
                  size="small"
                  label
                >
                  {{ tipo.tipo }}
                </VChip><span class="text-body-1 font-weight-medium">{{ tipo.registros.length === 1 ? tipo.registros[0].codigo_contrato : `${tipo.registros.length} contratos` }}</span><VChip
                  v-if="tipo.registros.length === 1"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ tipo.registros[0].modalidad_credencial }}
                </VChip>
              </div><div class="d-flex align-center gap-3 mt-1">
                <span class="text-caption text-disabled">Asignadas: <strong>{{ tipo.asignadas }}</strong></span><span class="text-caption text-disabled">Acreditadas: <strong>{{ tipo.utilizadas }}</strong></span><span class="text-caption text-disabled">Disponibles: <strong class="text-success">{{ tipo.disponibles }}</strong></span>
              </div><div
                v-if="tipo.registros.length === 1 && tipo.registros[0].id_contrato"
                class="mt-1 text-caption text-disabled"
              >
                {{ tipo.registros[0].pabellon || 'Sin pabellón' }} | Stand: {{ tipo.registros[0].stands?.join(', ') || 'Sin stand' }}<span v-if="tipo.registros[0].metraje_total"> | {{ tipo.registros[0].metraje_total }} m²</span>
              </div>
            </div><div class="d-flex align-center gap-3 ms-auto acreditacion-acciones">
              <AppTextField
                :model-value="busqueda[tipo.tipo] || ''"
                placeholder="Buscar por nombre o CI..."
                append-inner-icon="tabler-search"
                single-line
                clearable
                hide-details
                class="acreditados-tipo-buscador"
                @update:model-value="actualizarBusqueda(tipo.tipo, $event)"
              /><VBtn
                v-if="tipoTieneDisponibles(tipo)"
                color="primary"
                prepend-icon="tabler-user-plus"
                @click="abrirAcreditar(tipo)"
              >
                Agregar acreditado
              </VBtn>
            </div>
          </div>
        </VCardText>
        <VExpandTransition>
          <div v-if="tipoAbierto(tipo.tipo)">
            <VDivider />
            <div
              v-if="seleccionadosDeTipo(tipo).length"
              class="d-flex align-center justify-space-between flex-wrap gap-2 pa-3 px-4 bg-light-primary rounded"
            >
              <div class="d-flex align-center gap-2">
                <VIcon
                  icon="tabler-checkbox"
                  color="primary"
                />
                <span class="text-body-2 font-weight-medium">
                  {{ seleccionadosDeTipo(tipo).length }} credencial{{ seleccionadosDeTipo(tipo).length === 1 ? '' : 'es' }} seleccionada{{ seleccionadosDeTipo(tipo).length === 1 ? '' : 's' }}
                </span>
              </div>
              <div class="d-flex align-center gap-2">
                <VBtn
                  color="info"
                  size="small"
                  variant="flat"
                  prepend-icon="tabler-send"
                  @click="enviarSeleccionadas(tipo)"
                >
                  Enviar Credencial
                </VBtn>
                <VBtn
                  color="primary"
                  size="small"
                  variant="flat"
                  prepend-icon="tabler-file-download"
                  @click="descargarSeleccionadas(tipo)"
                >
                  Descargar Seleccionadas
                </VBtn>
              </div>
            </div>
            <VTable
              density="compact"
              class="contrato-credencial-table"
            >
              <thead>
                <tr>
                  <th class="text-center">
                    <VCheckbox
                      :model-value="todosSeleccionados(tipo)"
                      :indeterminate="seleccionParcial(tipo)"
                      :disabled="!acreditadosFiltrados(tipo).some(esDigital)"
                      hide-details
                      density="compact"
                      @update:model-value="alternarTodos(tipo, $event)"
                    />
                  </th>
                  <th>ACREDITADO</th>
                  <th>CELULAR</th>
                  <th>CORREO</th>
                  <th>TIPO DOCUMENTO</th>
                  <th>TIPO</th>
                  <th>ESTADO</th>
                  <th class="text-center">
                    IMPRESIONES / DESCARGAS
                  </th>
                  <th class="text-end">
                    ACCIONES
                  </th>
                </tr>
              </thead><tbody>
                <tr
                  v-for="acreditado in acreditadosPaginados(tipo)"
                  :key="acreditado.id_credencial"
                >
                  <td class="text-center">
                    <VCheckbox
                      :model-value="credencialesSeleccionadas.includes(acreditado.id_credencial)"
                      :disabled="!esDigital(acreditado)"
                      hide-details
                      density="compact"
                      @update:model-value="alternarSeleccion(acreditado, $event)"
                    />
                  </td>
                  <td>
                    <div class="d-flex align-center gap-3">
                      <VAvatar
                        size="40"
                        rounded="sm"
                        color="secondary"
                        variant="tonal"
                      >
                        <VImg
                          v-if="urlFoto(acreditado.foto)"
                          :src="urlFoto(acreditado.foto)"
                          cover
                        />
                        <VIcon
                          v-else
                          icon="tabler-user"
                        />
                      </VAvatar>
                      <div>
                        <div class="font-weight-medium">
                          {{ acreditado.nombre }}
                        </div>
                        <div class="text-caption text-medium-emphasis">
                          CI: {{ acreditado.ci }}<span v-if="acreditado.exp"> {{ acreditado.exp }}</span>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>{{ acreditado.celular || 'Sin datos' }}</td>
                  <td>{{ acreditado.email || 'Sin datos' }}</td>
                  <td>{{ acreditado.tipo_documento || 'Sin datos' }}</td>
                  <td>
                    <VChip
                      size="small"
                      variant="tonal"
                      color="info"
                    >
                      {{ acreditado.modalidad_credencial }}
                    </VChip>
                  </td><td>
                    <VChip
                      size="small"
                      variant="tonal"
                      :color="acreditado.estado_credencial === 'Activo' ? 'success' : 'warning'"
                    >
                      {{ acreditado.estado_credencial }}
                    </VChip>
                  </td><td class="text-center">
                    <template v-if="acreditado.descargas">
                      <VChip
                        size="small"
                        variant="tonal"
                        color="primary"
                      >
                        {{ acreditado.descargas.cantidad }}x
                      </VChip><div class="text-caption text-medium-emphasis mt-1">
                        {{ acreditado.descargas.ultima_fecha }}
                      </div>
                    </template><span v-else>—</span>
                  </td>
                  <td class="text-end">
                    <VBtn
                      v-if="esDigital(acreditado)"
                      icon
                      size="small"
                      variant="text"
                      color="primary"
                      @click="descargarIndividual(acreditado.id_credencial)"
                    >
                      <VIcon icon="tabler-download" />
                      <VTooltip activator="parent">
                        Descargar credencial digital
                      </VTooltip>
                    </VBtn>
                  </td>
                </tr><tr v-if="!acreditadosFiltrados(tipo).length">
                  <td
                    colspan="9"
                    class="text-center text-medium-emphasis py-5"
                  >
                    Sin acreditados registrados.
                  </td>
                </tr>
              </tbody>
            </VTable><div
              v-if="acreditadosFiltrados(tipo).length"
              class="d-flex align-center justify-space-between pa-4"
            >
              <span class="text-body-2 text-medium-emphasis">Elementos por página: {{ elementosPorPagina }}</span><VPagination
                v-model="paginasTipo[tipo.tipo]"
                :length="totalPaginas(tipo)"
                :total-visible="5"
                density="comfortable"
              />
            </div>
          </div>
        </VExpandTransition>
      </VCard>
    </template>
    <VDialog
      v-model="dialogAcreditar"
      max-width="640"
      scrollable
    >
      <VCard class="acreditacion-dialog-card">
        <VCardTitle class="acreditacion-dialog-header d-flex align-center pa-5">
          <div class="acreditacion-dialog-icon me-3">
            <VIcon icon="tabler-user-plus" />
          </div><div>
            <div class="text-h5 font-weight-bold">
              Agregar acreditado
            </div><div class="text-caption text-medium-emphasis">
              {{ empresa?.nombre_empresa }} | {{ tipoSeleccionado?.tipo }}
            </div>
          </div><VSpacer /><VBtn
            icon
            variant="tonal"
            size="small"
            @click="dialogAcreditar = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>
        <VDivider />
        <VCardText class="acreditacion-dialog-body pa-5 pt-4">
          <VAlert
            v-if="errorFormulario"
            type="error"
            density="compact"
            variant="tonal"
            class="mb-4"
          >
            {{ errorFormulario }}
          </VAlert>
          <div class="acreditacion-section-title mb-3">
            <VIcon
              icon="tabler-id-badge-2"
              size="18"
              class="me-2"
            />
            Datos del acreditado
          </div>
          <VSelect
            v-if="asignacionesDisponibles.length > 1"
            v-model="asignacionSeleccionada"
            :items="asignacionesDisponibles"
            item-title="etiqueta"
            item-value="clave"
            label="Asignar credencial a *"
            variant="outlined"
            class="mb-4"
          />
          <VAlert
            v-if="resultadoCi"
            :type="resultadoCi.type"
            density="compact"
            variant="tonal"
            class="mb-4"
          >
            {{ resultadoCi.text }}
          </VAlert>
          <VRow>
            <VCol cols="12">
              <VRow dense>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="formulario.ci"
                    label="Carnet de identidad *"
                    variant="outlined"
                    :error-messages="erroresCampos.ci"
                    :loading="buscandoCi"
                    :readonly="Boolean(formulario.idExpositor)"
                    @update:model-value="limpiarErrorCampo('ci')"
                    @blur="buscarPorCi"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="formulario.tipoDocumento"
                    label="Documento *"
                    :items="['Cédula de identidad', 'Pasaporte', 'Documento extranjero']"
                    variant="outlined"
                    :disabled="!camposHabilitados"
                    :error-messages="erroresCampos.tipoDocumento"
                    @update:model-value="limpiarErrorCampo('tipoDocumento')"
                  />
                </VCol>
              </VRow>
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="formulario.nombre"
                label="Nombre completo *"
                variant="outlined"
                :disabled="!camposHabilitados"
                :error-messages="erroresCampos.nombre"
                @update:model-value="limpiarErrorCampo('nombre')"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="formulario.codigoPais"
                label="Código de país"
                :items="codigosPais"
                variant="outlined"
                density="comfortable"
                :disabled="!camposHabilitados"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="formulario.numeroCelular"
                label="Número de celular (opcional)"
                variant="outlined"
                density="comfortable"
                inputmode="numeric"
                :maxlength="formulario.codigoPais === '591' ? 8 : 14"
                :error-messages="erroresCampos.celular || (!celularValido ? (formulario.codigoPais === '591' ? 'Bolivia requiere 8 dígitos.' : 'Ingrese entre 7 y 14 dígitos.') : undefined)"
                :disabled="!camposHabilitados"
                @update:model-value="formulario.numeroCelular = String($event).replace(/\D/g, ''); limpiarErrorCampo('celular')"
              />
              <div
                class="text-caption mt-1"
                :class="celularValido ? 'text-medium-emphasis' : 'text-error'"
              >
                {{ formulario.codigoPais === '591' ? 'Bolivia requiere 8 dígitos.' : 'Ingrese entre 7 y 14 dígitos.' }}
              </div>
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model="formulario.email"
                label="Correo electrónico (opcional)"
                type="email"
                variant="outlined"
                :disabled="!camposHabilitados"
                :error-messages="erroresCampos.email"
                @update:model-value="limpiarErrorCampo('email')"
              />
            </VCol>
            <VCol
              cols="12"
              class="d-flex align-center justify-center gap-4"
            >
              <input
                ref="inputFoto"
                type="file"
                accept="image/png,image/jpeg"
                class="d-none"
                :disabled="!camposHabilitados || comprimiendoFoto"
                @change="seleccionarFoto"
              ><button
                type="button"
                class="acreditacion-photo-picker"
                :class="{ 'acreditacion-photo-picker--error': erroresCampos.foto }"
                :aria-invalid="Boolean(erroresCampos.foto)"
                :disabled="!camposHabilitados || comprimiendoFoto"
                @click="inputFoto?.click()"
              >
                <VImg
                  v-if="previewFoto"
                  :src="previewFoto"
                  cover
                  class="acreditacion-photo-preview"
                /><VIcon
                  v-else
                  icon="tabler-camera"
                  size="40"
                />
              </button>
              <div>
                <p class="text-body-1 font-weight-medium mb-1">
                  Fotografía *
                </p>
                <p class="text-body-2 text-medium-emphasis mb-2">
                  JPG o PNG, máximo 2 MB. Las fotos mayores se reducen y convierten automáticamente a JPG.
                </p>
                <p
                  v-if="comprimiendoFoto"
                  class="text-primary text-caption mb-2"
                  role="status"
                >
                  Comprimiendo fotografía…
                </p>
                <p
                  v-if="erroresCampos.foto"
                  class="text-error text-caption mb-2"
                >
                  {{ mensajeErrorCampo('foto') }}
                </p>
                <VBtn
                  variant="tonal"
                  color="primary"
                  prepend-icon="tabler-upload"
                  :loading="comprimiendoFoto"
                  :disabled="!camposHabilitados || comprimiendoFoto"
                  @click="inputFoto?.click()"
                >
                  Cargar Fotografía
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="acreditacion-dialog-actions pa-5 d-flex justify-end gap-2">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="dialogAcreditar = false"
          >
            Cancelar
          </VBtn><VBtn
            color="primary"
            :loading="guardando"
            :disabled="ciAcreditadoEnEvento || comprimiendoFoto || Boolean(erroresCampos.foto)"
            @click="guardarAcreditado"
          >
            Acreditar expositor
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="dialogConflicto"
      max-width="620"
      persistent
    >
      <VCard
        v-if="expositorConflicto"
        class="acreditacion-dialog-card"
      >
        <VCardTitle class="acreditacion-dialog-header d-flex align-center pa-5">
          <div class="acreditacion-dialog-icon me-3">
            <VIcon
              icon="tabler-id-badge-off"
              size="24"
            />
          </div>
          <div>
            <div class="text-h5 font-weight-bold">
              Expositor ya acreditado
            </div>
            <div class="text-caption text-medium-emphasis mt-1">
              No puede recibir otra credencial en este evento
            </div>
          </div>
          <VSpacer />
          <VBtn
            icon
            variant="tonal"
            size="small"
            @click="cerrarConflicto"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>
        <VDivider />
        <VCardText class="acreditacion-dialog-body pa-5">
          <div class="conflicto-acreditado-contenido">
            <div class="d-flex flex-column align-center text-center mb-5">
              <VAvatar
                size="132"
                rounded="circle"
                color="secondary"
                variant="tonal"
              >
                <VImg
                  v-if="urlFoto(expositorConflicto.foto)"
                  :src="urlFoto(expositorConflicto.foto)"
                  cover
                />
                <VIcon
                  v-else
                  icon="tabler-user"
                  size="44"
                />
              </VAvatar>
            </div>
            <div class="conflicto-acreditado-linea mb-4">
              <span>Nombre completo</span>
              <strong>{{ expositorConflicto.nombre || 'Sin datos' }}</strong>
            </div>
            <div class="conflicto-acreditado-linea mb-5">
              <span>Carnet de identidad</span>
              <strong>{{ expositorConflicto.ci || 'Sin datos' }}</strong>
            </div>
            <VAlert
              type="warning"
              variant="tonal"
              class="conflicto-acreditado-alert"
            >
              <div class="font-weight-medium">
                Credencial asignada en este evento
              </div>
              <div class="mt-1">
                Empresa: <strong>{{ expositorConflicto.credencial.empresa }}</strong>
              </div>
              <div>
                Tipo de credencial: <strong>{{ expositorConflicto.credencial.tipo }}</strong>
              </div>
            </VAlert>
          </div>
        </VCardText>
        <VDivider />
        <VCardActions class="acreditacion-dialog-actions pa-5 d-flex justify-end">
          <VBtn
            color="secondary"
            variant="tonal"
            @click="cerrarConflicto"
          >
            Cerrar
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <VSnackbar
      v-model="mostrarMensaje"
      color="success"
      location="top end"
      :timeout="15000"
    >
      {{ mensaje }}
      <template #actions>
        <VBtn
          variant="text"
          @click="mostrarMensaje = false"
        >
          Cerrar
        </VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style scoped>
.contrato-credencial-table { min-inline-size: 980px; }
.contrato-credencial-table th { white-space: nowrap; }
.contrato-credencial-table td { white-space: nowrap; padding-block: 14px; }
.acreditados-tipo-buscador { inline-size: 19rem !important; max-inline-size: 19rem !important; }
.acreditacion-acciones { margin-inline-start: auto; }
.acreditacion-dialog-card { overflow: hidden; border-radius: 16px; }
.acreditacion-dialog-header { background: linear-gradient(135deg, rgba(var(--v-theme-primary), .11), rgba(var(--v-theme-primary), .04)); }
.acreditacion-dialog-icon { display: inline-flex; align-items: center; justify-content: center; inline-size: 44px; block-size: 44px; border-radius: 12px; background: rgba(var(--v-theme-primary), .16); color: rgb(var(--v-theme-primary)); }
.acreditacion-dialog-body { background: rgba(var(--v-theme-on-surface), .01); }
.acreditacion-dialog-actions { background: rgba(var(--v-theme-primary), .03); }
.acreditacion-photo-picker { display: inline-flex; align-items: center; justify-content: center; inline-size: 140px; block-size: 140px; padding: 0; overflow: hidden; border: 2px dashed rgba(var(--v-theme-on-surface), .48); border-radius: 50%; background: rgba(var(--v-theme-on-surface), .02); color: rgba(var(--v-theme-on-surface), .7); cursor: pointer; }
.acreditacion-photo-picker--error { border-color: rgb(var(--v-theme-error)); color: rgb(var(--v-theme-error)); }
.acreditacion-photo-preview { inline-size: 100%; block-size: 100%; }
.conflicto-acreditado-contenido { inline-size: min(100%, 560px); margin-inline: auto; }
.conflicto-acreditado-linea { text-align: center; }
.conflicto-acreditado-linea span { display: block; color: rgb(var(--v-theme-primary)); font-weight: 600; }
.conflicto-acreditado-linea strong { display: block; margin-block-start: 4px; color: rgba(var(--v-theme-on-surface), .88); font-size: 1.05rem; }
.conflicto-acreditado-alert { text-align: center; border: 1px solid rgba(var(--v-theme-warning), .2); }
@media (max-width: 700px) { .acreditacion-acciones { inline-size: 100%; justify-content: flex-end; } .acreditados-tipo-buscador { inline-size: min(100%, 19rem) !important; max-inline-size: 100% !important; } }
</style>
