<template>
  <BaseCard>
    <template #header>
      <h6 class="text-sm font-semibold leading-5 text-gray-900">
        {{ $t('invoices.ai.title') }}
      </h6>
      <p class="mt-1 text-sm text-gray-500">
        {{ $t('invoices.ai.description') }}
      </p>
    </template>

    <p v-if="!speechSupported" class="mb-4 text-sm text-gray-500">
      {{ $t('invoices.ai.speech_not_supported') }}
    </p>

    <BaseTextarea
      v-model="text"
      :placeholder="$t('invoices.ai.placeholder')"
      :disabled="isProcessing"
      rows="3"
    />

    <div class="flex flex-wrap items-center gap-3 mt-4">
      <BaseButton
        v-if="speechSupported"
        type="button"
        variant="primary-outline"
        :disabled="isProcessing"
        @click="toggleListening"
      >
        <template #left="slotProps">
          <BaseIcon name="MicrophoneIcon" :class="slotProps.class" />
        </template>
        {{
          isListening
            ? $t('invoices.ai.stop_listening')
            : $t('invoices.ai.start_listening')
        }}
      </BaseButton>

      <BaseButton
        type="button"
        :loading="isProcessing"
        :disabled="isProcessing || !text.trim()"
        @click="fillForm"
      >
        {{ $t('invoices.ai.fill_form') }}
      </BaseButton>

      <span v-if="isListening" class="text-sm text-primary-500">
        {{ $t('invoices.ai.listening') }}
      </span>
    </div>

    <BaseInfoAlert
      v-if="draft"
      class="mt-4"
      :title="$t('invoices.ai.review_hint')"
      :lists="warningMessages"
      :actions="[]"
      @hide="draft = null"
    />
  </BaseCard>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useInvoiceStore } from '@/scripts/admin/stores/invoice'
import { useNotificationStore } from '@/scripts/stores/notification'

const invoiceStore = useInvoiceStore()
const notificationStore = useNotificationStore()
const { t } = useI18n()

const text = ref('')
const isListening = ref(false)
const isProcessing = ref(false)
const draft = ref(null)

const SpeechRecognition =
  window.SpeechRecognition || window.webkitSpeechRecognition
const speechSupported = !!SpeechRecognition
let recognition = null

const warningMessages = computed(() => {
  if (!draft.value) {
    return []
  }

  return draft.value.warnings.map((warning) => {
    let message = t(`invoices.ai.warnings.${warning.code}`, {
      name: warning.name,
      index: warning.index,
    })

    const candidates = warningCandidates(warning)
    if (candidates.length) {
      message +=
        ' ' +
        t('invoices.ai.candidates', {
          names: candidates.map((candidate) => candidate.name).join(', '),
        })
    }

    return message
  })
})

function warningCandidates(warning) {
  if (warning.code === 'customer_ambiguous') {
    return draft.value.customer.candidates
  }

  if (warning.code === 'item_ambiguous' && warning.index) {
    return draft.value.items[warning.index - 1]?.candidates ?? []
  }

  return []
}

function toggleListening() {
  if (isListening.value) {
    recognition?.stop()
    return
  }

  const baseText = text.value.trim()

  recognition = new SpeechRecognition()
  recognition.lang = navigator.language || 'nl-NL'
  recognition.interimResults = true
  recognition.continuous = false

  recognition.onresult = (event) => {
    const transcript = Array.from(event.results)
      .map((result) => result[0].transcript)
      .join('')

    text.value = [baseText, transcript.trim()].filter(Boolean).join(' ')
  }

  recognition.onerror = () => {
    isListening.value = false
    notificationStore.showNotification({
      type: 'error',
      message: t('invoices.ai.speech_error'),
    })
  }

  recognition.onend = () => {
    isListening.value = false
  }

  isListening.value = true
  recognition.start()
}

async function fillForm() {
  if (!text.value.trim()) {
    return
  }

  recognition?.stop()
  isProcessing.value = true
  draft.value = null

  try {
    const response = await invoiceStore.parseInvoiceText(text.value.trim())
    await invoiceStore.applyInvoiceDraft(response.data.data)
    draft.value = response.data.data
  } catch {
    // The store already shows the error notification.
  } finally {
    isProcessing.value = false
  }
}

onBeforeUnmount(() => {
  if (recognition) {
    recognition.onend = null
    recognition.onerror = null
    recognition.onresult = null
    recognition.abort()
  }
})
</script>
