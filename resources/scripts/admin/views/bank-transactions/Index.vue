<template>
  <BasePage class="bank-transactions">
    <BasePageHeader :title="$t('bank_transactions.title')">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem
          :title="$t('payments.payment', 2)"
          to="/admin/payments"
        />
        <BaseBreadcrumbItem
          :title="$t('bank_transactions.title')"
          to="#"
          active
        />
      </BaseBreadcrumb>
    </BasePageHeader>

    <BaseCard class="mt-6">
      <BaseInputGroup
        :label="$t('bank_transactions.upload_camt')"
        :help-text="$t('bank_transactions.upload_help')"
      >
        <BaseFileUploader
          v-model="statementFiles"
          accept=".xml,text/xml,application/xml"
          input-field-name="file"
          @change="onFileChange"
          @remove="onFileRemove"
        />
      </BaseInputGroup>

      <div class="flex justify-end mt-4">
        <BaseButton
          variant="primary"
          :disabled="!selectedFile || bankTransactionStore.isUploading"
          :loading="bankTransactionStore.isUploading"
          @click="importStatement"
        >
          <template #left="slotProps">
            <BaseIcon name="UploadIcon" :class="slotProps.class" />
          </template>
          {{ $t('bank_transactions.import_bank_statement') }}
        </BaseButton>
      </div>

      <div
        v-if="bankTransactionStore.lastImport"
        class="grid grid-cols-2 gap-4 mt-6 text-sm md:grid-cols-5"
      >
        <div v-for="item in importSummary" :key="item.label">
          <BaseLabel>{{ item.label }}</BaseLabel>
          <p class="text-lg font-semibold text-gray-900">{{ item.value }}</p>
        </div>
      </div>
    </BaseCard>

    <div class="relative mt-6 table-container">
      <BaseTabGroup class="-mb-5" @change="setStatusFilter">
        <BaseTab
          v-for="tab in tabs"
          :key="tab.filter"
          :title="tab.title"
          :filter="tab.filter"
        />
      </BaseTabGroup>

      <BaseTable
        ref="tableComponent"
        :data="fetchData"
        :columns="transactionColumns"
        class="mt-10"
      >
        <template #cell-booking_date="{ row }">
          {{ row.data.booking_date }}
        </template>

        <template #cell-counterparty="{ row }">
          <BaseText :text="row.data.counterparty_name || '-'" tag="span" />
          <span
            v-if="row.data.counterparty_iban"
            class="block text-xs text-gray-500"
          >
            {{ row.data.counterparty_iban }}
          </span>
        </template>

        <template #cell-description="{ row }">
          <BaseText :text="row.data.description || '-'" :length="60" />
        </template>

        <template #cell-amount="{ row }">
          <BaseFormatMoney
            :amount="row.data.amount"
            :currency="currencyFor(row.data.currency_code)"
          />
        </template>

        <template #cell-status="{ row }">
          <BaseBadge
            :bg-color="statusColors[row.data.status].bg"
            :color="statusColors[row.data.status].color"
          >
            {{ $t(`bank_transactions.statuses.${row.data.status}`) }}
          </BaseBadge>
          <span
            v-if="row.data.match_reason"
            class="block mt-1 text-xs text-gray-500"
          >
            {{ $t(`bank_transactions.reasons.${row.data.match_reason}`) }}
          </span>
        </template>

        <template #cell-candidates="{ row }">
          <template v-if="row.data.candidates && row.data.candidates.length">
            <span
              v-for="candidate in row.data.candidates"
              :key="candidate.id"
              class="block text-sm"
            >
              {{ candidate.invoice_number }} · {{ candidate.customer_name }}
            </span>
          </template>
          <template v-else-if="row.data.payments && row.data.payments.length">
            <router-link
              v-for="payment in row.data.payments"
              :key="payment.id"
              :to="`/admin/payments/${payment.id}/view`"
              class="block text-sm font-medium text-primary-500"
            >
              {{ payment.payment_number }}
              <span v-if="payment.invoice" class="text-gray-500">
                ({{ payment.invoice.invoice_number }})
              </span>
            </router-link>
          </template>
          <span v-else>-</span>
        </template>

        <template v-if="canProcess" #cell-actions="{ row }">
          <BaseDropdown v-if="isLinkable(row.data)">
            <template #activator>
              <BaseIcon name="DotsHorizontalIcon" class="text-gray-500" />
            </template>

            <BaseDropdownItem @click="openLinkModal(row.data)">
              <BaseIcon name="LinkIcon" class="mr-3 text-gray-600" />
              {{ $t('bank_transactions.link_to_invoice') }}
            </BaseDropdownItem>

            <BaseDropdownItem
              v-if="isOpen(row.data)"
              @click="ignoreTransaction(row.data)"
            >
              <BaseIcon name="EyeOffIcon" class="mr-3 text-gray-600" />
              {{ $t('bank_transactions.ignore') }}
            </BaseDropdownItem>
          </BaseDropdown>
        </template>
      </BaseTable>
    </div>

    <BaseModal :show="linkModal.show" @close="closeLinkModal">
      <template #header>
        <div class="flex justify-between w-full">
          {{ $t('bank_transactions.link_to_invoice') }}
          <BaseIcon
            name="XIcon"
            class="w-6 h-6 text-gray-500 cursor-pointer"
            @click="closeLinkModal"
          />
        </div>
      </template>

      <div v-if="linkModal.transaction" class="px-6 pb-3">
        <p class="mb-4 text-sm text-gray-700">
          {{ linkModal.transaction.counterparty_name }} ·
          <BaseFormatMoney
            :amount="linkModal.transaction.amount"
            :currency="currencyFor(linkModal.transaction.currency_code)"
          />
        </p>

        <BaseInputGroup
          :label="$t('bank_transactions.select_invoices')"
          required
        >
          <BaseMultiselect
            v-model="linkModal.invoiceIds"
            mode="tags"
            value-prop="id"
            label="label"
            track-by="label"
            searchable
            resolve-on-load
            :filter-results="false"
            :delay="500"
            :min-chars="0"
            :options="searchInvoices"
          />
        </BaseInputGroup>

        <div v-if="linkModal.invoiceIds.length" class="mt-4 text-sm">
          <p class="text-gray-700">
            {{ $t('bank_transactions.selected_due') }}:
            <BaseFormatMoney
              :amount="selectedDueAmount"
              :currency="currencyFor(linkModal.transaction.currency_code)"
            />
          </p>
          <p v-if="remainingAmount > 0" class="mt-1 text-gray-500">
            {{ $t('bank_transactions.remaining_as_credit') }}:
            <BaseFormatMoney
              :amount="remainingAmount"
              :currency="currencyFor(linkModal.transaction.currency_code)"
            />
          </p>
        </div>
      </div>

      <div
        class="z-0 flex justify-end p-4 border-t border-gray-200 border-solid"
      >
        <BaseButton
          class="mr-3"
          variant="primary-outline"
          type="button"
          @click="closeLinkModal"
        >
          {{ $t('general.cancel') }}
        </BaseButton>

        <BaseButton
          variant="primary"
          :loading="linkModal.isSaving"
          :disabled="!linkModal.invoiceIds.length || linkModal.isSaving"
          @click="linkTransaction"
        >
          {{ $t('bank_transactions.link') }}
        </BaseButton>
      </div>
    </BaseModal>
  </BasePage>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBankTransactionStore } from '@/scripts/admin/stores/bank-transaction'
import { useInvoiceStore } from '@/scripts/admin/stores/invoice'
import { useGlobalStore } from '@/scripts/admin/stores/global'
import { useUserStore } from '@/scripts/admin/stores/user'
import { useDialogStore } from '@/scripts/stores/dialog'
import abilities from '@/scripts/admin/stub/abilities'

const { t } = useI18n()

const bankTransactionStore = useBankTransactionStore()
const invoiceStore = useInvoiceStore()
const globalStore = useGlobalStore()
const userStore = useUserStore()
const dialogStore = useDialogStore()

const OPEN_STATUSES = ['UNMATCHED', 'AMBIGUOUS']

const tableComponent = ref(null)
const statementFiles = ref([])
const selectedFile = ref(null)
const status = ref('review')
const invoiceOptions = reactive({})

const linkModal = reactive({
  show: false,
  transaction: null,
  invoiceIds: [],
  isSaving: false,
})

const tabs = computed(() => [
  { title: t('bank_transactions.tab_review'), filter: 'review' },
  { title: t('bank_transactions.tab_matched'), filter: 'MATCHED' },
  { title: t('bank_transactions.tab_linked'), filter: 'LINKED' },
  { title: t('bank_transactions.tab_ignored'), filter: 'IGNORED' },
])

const statusColors = {
  UNMATCHED: { bg: '#FEE2E2', color: '#991B1B' },
  AMBIGUOUS: { bg: '#FEF3C7', color: '#92400E' },
  MATCHED: { bg: '#D1FAE5', color: '#065F46' },
  LINKED: { bg: '#DBEAFE', color: '#1E40AF' },
  IGNORED: { bg: '#F3F4F6', color: '#374151' },
}

const canProcess = computed(() =>
  userStore.hasAbilities(abilities.CREATE_PAYMENT),
)

const transactionColumns = computed(() => [
  { key: 'booking_date', label: t('bank_transactions.date'), sortable: false },
  {
    key: 'counterparty',
    label: t('bank_transactions.counterparty'),
    sortable: false,
  },
  {
    key: 'description',
    label: t('bank_transactions.description'),
    sortable: false,
  },
  { key: 'amount', label: t('bank_transactions.amount'), sortable: false },
  { key: 'status', label: t('bank_transactions.status'), sortable: false },
  {
    key: 'candidates',
    label: t('bank_transactions.candidates'),
    sortable: false,
  },
  {
    key: 'actions',
    label: '',
    tdClass: 'text-right text-sm font-medium',
    sortable: false,
  },
])

const importSummary = computed(() => {
  const result = bankTransactionStore.lastImport

  return [
    {
      label: t('bank_transactions.summary_read'),
      value: result.transaction_count,
    },
    { label: t('bank_transactions.summary_new'), value: result.new_count },
    {
      label: t('bank_transactions.summary_duplicates'),
      value: result.duplicate_count,
    },
    {
      label: t('bank_transactions.summary_matched'),
      value: result.matched_count,
    },
    {
      label: t('bank_transactions.summary_review'),
      value: result.review_count,
    },
  ]
})

const selectedDueAmount = computed(() =>
  linkModal.invoiceIds.reduce(
    (sum, id) => sum + parseInt(invoiceOptions[id]?.due_amount || 0),
    0,
  ),
)

const remainingAmount = computed(() =>
  linkModal.transaction
    ? Math.max(linkModal.transaction.amount - selectedDueAmount.value, 0)
    : 0,
)

globalStore.fetchCurrencies()

function currencyFor(code) {
  return globalStore.currencies.find((currency) => currency.code === code)
}

function isOpen(transaction) {
  return OPEN_STATUSES.includes(transaction.status)
}

function isLinkable(transaction) {
  return isOpen(transaction) || transaction.status === 'IGNORED'
}

function onFileChange(fieldName, file) {
  selectedFile.value = file
}

function onFileRemove() {
  selectedFile.value = null
}

async function importStatement() {
  if (!selectedFile.value) {
    return
  }

  try {
    await bankTransactionStore.importStatement(selectedFile.value)
    selectedFile.value = null
    statementFiles.value = []
    refreshTable()
  } catch (err) {
    // The store already shows the error notification.
  }
}

async function fetchData({ page }) {
  const response = await bankTransactionStore.fetchTransactions({
    status: status.value,
    page,
  })

  return {
    data: response.data.data,
    pagination: {
      totalPages: response.data.meta.last_page,
      currentPage: page,
      totalCount: response.data.meta.total,
      limit: 10,
    },
  }
}

function refreshTable() {
  tableComponent.value && tableComponent.value.refresh()
}

function setStatusFilter(tab) {
  const selected = tabs.value.find((item) => item.title === tab.title)

  if (!selected || selected.filter === status.value) {
    return
  }

  status.value = selected.filter
  refreshTable()
}

function rememberInvoiceOption(option) {
  invoiceOptions[option.id] = option

  return option
}

async function searchInvoices(search) {
  const transaction = linkModal.transaction

  if (!transaction) {
    return []
  }

  const candidates = (transaction.candidates || []).map((candidate) =>
    rememberInvoiceOption({
      id: candidate.id,
      due_amount: candidate.due_amount,
      label: `${candidate.invoice_number} · ${candidate.customer_name || ''}`,
    }),
  )

  const response = await invoiceStore.fetchInvoices({ search, limit: 10 })

  const found = response.data.data
    .filter(
      (invoice) =>
        parseInt(invoice.due_amount) > 0 &&
        invoice.currency?.code === transaction.currency_code,
    )
    .map((invoice) =>
      rememberInvoiceOption({
        id: invoice.id,
        due_amount: invoice.due_amount,
        label: `${invoice.invoice_number} · ${invoice.customer?.name || ''}`,
      }),
    )

  const options = [...candidates]

  found.forEach((invoice) => {
    if (!options.some((option) => option.id === invoice.id)) {
      options.push(invoice)
    }
  })

  return options
}

function openLinkModal(transaction) {
  linkModal.transaction = transaction
  linkModal.invoiceIds = []
  linkModal.show = true
}

function closeLinkModal() {
  linkModal.show = false

  setTimeout(() => {
    linkModal.transaction = null
    linkModal.invoiceIds = []
  }, 300)
}

async function linkTransaction() {
  linkModal.isSaving = true

  try {
    await bankTransactionStore.linkTransaction(
      linkModal.transaction.id,
      linkModal.invoiceIds,
    )
    closeLinkModal()
    refreshTable()
  } catch (err) {
    // The store already shows the error notification.
  } finally {
    linkModal.isSaving = false
  }
}

function ignoreTransaction(transaction) {
  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: t('bank_transactions.confirm_ignore'),
      yesLabel: t('general.ok'),
      noLabel: t('general.cancel'),
      variant: 'danger',
      hideNoButton: false,
      size: 'lg',
    })
    .then(async (res) => {
      if (res) {
        await bankTransactionStore.ignoreTransaction(transaction.id)
        refreshTable()
      }
    })
}
</script>
