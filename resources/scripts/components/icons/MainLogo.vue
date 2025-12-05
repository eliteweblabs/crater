<template>
  <img 
    v-if="companyLogoUrl" 
    :src="companyLogoUrl" 
    alt="Company Logo" 
    class="h-6 max-w-[180px] object-contain"
  />
</template>

<script setup>
import { computed } from 'vue'

const companyLogoUrl = computed(() => window.company_logo_url || null)
</script>
