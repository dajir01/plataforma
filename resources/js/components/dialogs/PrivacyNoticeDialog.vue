<script setup>
defineProps({
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

defineEmits(['update:isDialogVisible'])
</script>

<template>
  <VDialog
    :model-value="isDialogVisible"
    max-width="720"
    scrollable
    @update:model-value="$emit('update:isDialogVisible', $event)"
  >
    <VCard>
      <VCardTitle class="d-flex align-center pa-5">
        <span class="text-h5 font-weight-bold">Aviso de Privacidad</span>
        <VSpacer />
        <VBtn
          icon
          variant="tonal"
          size="small"
          @click="$emit('update:isDialogVisible', false)"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </VCardTitle>
      <VDivider />

      <VCardText class="pa-5" style="max-height: 60vh;">
        <p class="text-body-1">
          En cumplimiento de las normativas aplicables en materia de protección de datos personales, FEXCO informa a
          las empresas expositoras, sus representantes y a las personas que acreditan como parte de su equipo, que la
          información recolectada a través de la <strong>Plataforma de Expositores FEXCO</strong> será tratada de
          manera confidencial y utilizada únicamente para los fines relacionados con la organización, gestión y
          comunicación del evento.
        </p>

        <h6 class="text-h6 mt-6 mb-2">
          Datos que recolectamos
        </h6>
        <p class="text-body-1">
          Durante el uso de la plataforma se recolectan, entre otros:
        </p>
        <ul class="text-body-1 mb-4">
          <li>Datos de acceso de la empresa expositora (NIT, usuario).</li>
          <li>Datos de identificación de las personas acreditadas (nombre completo, carnet de identidad o documento equivalente).</li>
          <li>Datos de contacto (correo electrónico, número de celular).</li>
          <li>Fotografía utilizada para la generación de la credencial de acceso al evento.</li>
        </ul>

        <h6 class="text-h6 mt-6 mb-2">
          Finalidad del tratamiento de datos
        </h6>
        <ul class="text-body-1 mb-4">
          <li>Registro y acreditación de expositores y su personal.</li>
          <li>Generación y validación de credenciales de acceso al evento.</li>
          <li>Comunicación de información relevante sobre el evento.</li>
          <li>Gestión administrativa y logística.</li>
          <li>Cumplimiento de obligaciones legales y contractuales.</li>
        </ul>

        <h6 class="text-h6 mt-6 mb-2">
          Uso y resguardo de la información
        </h6>
        <ul class="text-body-1 mb-4">
          <li>Los datos personales no serán compartidos con terceros ajenos al evento sin autorización expresa del titular.</li>
          <li>Se implementan medidas de seguridad técnicas y administrativas para proteger la información contra accesos no autorizados, pérdida o alteración.</li>
          <li>El acceso a la plataforma requiere autenticación y cada empresa expositora únicamente puede consultar y administrar la información de su propia acreditación.</li>
        </ul>

        <h6 class="text-h6 mt-6 mb-2">
          Derechos del titular
        </h6>
        <p class="text-body-1 mb-4">
          El titular de los datos podrá ejercer en cualquier momento sus derechos de acceso, rectificación,
          actualización y eliminación de sus datos personales, enviando una solicitud formal al área de sistemas y
          acreditación del evento.
        </p>

        <h6 class="text-h6 mt-6 mb-2">
          Consentimiento
        </h6>
        <p class="text-body-1 mb-0">
          Al registrar los datos de las personas que acreditará para el evento, la empresa expositora declara contar
          con el consentimiento de dichas personas para el tratamiento de sus datos conforme a lo descrito en este
          aviso.
        </p>
      </VCardText>

      <VDivider />
      <VCardActions class="pa-5 justify-end">
        <VBtn
          color="primary"
          variant="tonal"
          @click="$emit('update:isDialogVisible', false)"
        >
          Cerrar
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
