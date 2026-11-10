<script setup>
const isPrivacyDialogVisible = ref(false)
</script>

<template>
  <div class="h-100 d-flex align-center justify-md-space-between justify-center">
    <!-- 👉 Footer: left content -->
    <span class="d-flex align-center text-medium-emphasis">
      &copy;
      {{ new Date().getFullYear() }}
      Desarrollado por
      <a
        href="https://pixinvent.com"
        target="_blank"
        rel="noopener noreferrer"
        class="text-primary ms-1"
      >FEXCO</a>
    </span>
    <!-- 👉 Footer: right content -->
    <span class="d-md-flex align-center gap-x-4 text-primary d-none">
      <a
        href="javascript:void(0)"
        @click="isPrivacyDialogVisible = true"
      >
        Aviso de Privacidad
      </a>
      <span>Plataforma Expositor FEXCO</span>
    </span>

    <PrivacyNoticeDialog v-model:is-dialog-visible="isPrivacyDialogVisible" />
  </div>
</template>
