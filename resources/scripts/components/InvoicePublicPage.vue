I
<template>
  <div class="h-screen overflow-y-auto min-h-0">
    <div class="bg-gradient-to-r from-primary-500 to-primary-400 h-5"></div>

    <div
      class="
        relative
        p-6
        pb-28
        px-4
        md:px-6
        w-full
        md:w-auto md:max-w-xl
        mx-auto
      "
    >
      <BasePageHeader :title="pageTitle || ''">
        <template #actions>
          <div
            class="
              flex flex-col
              md:flex-row
              absolute
              md:relative
              bottom-2
              left-0
              px-4
              md:px-0
              w-full
              md:space-x-4 md:space-y-0
              space-y-2
            "
          >
            <a :href="shareableLink" target="_blank" class="block w-full">
              <BaseButton
                variant="primary-outline"
                class="justify-center w-full"
              >
                {{ $t('general.download_pdf') }}
              </BaseButton>
            </a>

            <BaseButton
              v-if="
                !isLoading &&
                invoiceData &&
                invoiceData.paid_status !== 'PAID' &&
                invoiceData.unique_hash
              "
              variant="primary"
              class="justify-center"
              :disabled="!invoiceData || !invoiceData.unique_hash"
              @click="payInvoice"
            >
              {{ $t('general.pay_invoice') }}
            </BaseButton>
          </div>
        </template>
      </BasePageHeader>

      <InvoiceInformationCard :invoice="invoiceData" />
    </div>
  </div>
</template>

<script setup>
import axios from 'axios'
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import InvoiceInformationCard from '@/scripts/components/InvoiceInformationCard.vue'

let invoiceData = ref(null)
const isLoading = ref(true)
const route = useRoute()

loadInvoice()

async function loadInvoice() {
  try {
    isLoading.value = true
    // The hash parameter is actually an email log token for public invoice view
    let res = await axios.get(`/customer/invoices/${route.params.hash}`)
    invoiceData.value = res.data.data
    console.log('Invoice loaded:', invoiceData.value)
  } catch (error) {
    console.error('Error loading invoice:', error)
    if (error.response) {
      console.error('Response status:', error.response.status)
      console.error('Response data:', error.response.data)
    }
    // Handle error - could show error message to user
  } finally {
    isLoading.value = false
  }
}

const shareableLink = computed(() => {
  return route.path + '?pdf'
})

const customerLogo = computed(() => {
  if (window.customer_logo) {
    return window.customer_logo
  }

  return false
})

const pageTitle = computed(() => invoiceData.value?.invoice_number)

function payInvoice() {
  try {
    if (!invoiceData.value) {
      console.error('Invoice data is not loaded yet')
      alert('Invoice data is still loading. Please wait a moment and try again.')
      return
    }
    
    if (!invoiceData.value.unique_hash) {
      console.error('Invoice unique_hash is missing', invoiceData.value)
      alert('Unable to process payment: Invoice information is incomplete.')
      return
    }
    
    // Navigate to public Stripe checkout route (no auth required, server will redirect to Stripe)
    const paymentUrl = `/invoices/${invoiceData.value.unique_hash}/pay`
    console.log('Redirecting to payment:', paymentUrl)
    
    // Use window.location.replace to avoid Vue Router interference
    window.location.replace(paymentUrl)
  } catch (error) {
    console.error('Error in payInvoice:', error)
    alert('An error occurred while processing payment. Please try again.')
  }
}
</script>
