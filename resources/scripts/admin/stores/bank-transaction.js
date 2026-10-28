import axios from 'axios'
import { defineStore } from 'pinia'
import { useNotificationStore } from '@/scripts/stores/notification'
import { handleError } from '@/scripts/helpers/error-handling'

export const useBankTransactionStore = (useWindow = false) => {
  const defineStoreFunc = useWindow ? window.pinia.defineStore : defineStore
  const { global } = window.i18n

  return defineStoreFunc({
    id: 'bank-transaction',

    state: () => ({
      transactions: [],
      reviewCount: 0,
      lastImport: null,
      isUploading: false,
    }),

    actions: {
      importStatement(file) {
        const notificationStore = useNotificationStore()
        const formData = new FormData()
        formData.append('file', file)

        this.isUploading = true

        return new Promise((resolve, reject) => {
          axios
            .post('/api/v1/bank-statements/import', formData)
            .then((response) => {
              this.lastImport = response.data.data
              this.isUploading = false

              notificationStore.showNotification({
                type: 'success',
                message: global.t('bank_transactions.import_success', {
                  new: this.lastImport.new_count,
                  matched: this.lastImport.matched_count,
                }),
              })
              resolve(response)
            })
            .catch((err) => {
              this.isUploading = false
              const error = err.response?.data?.error

              if (error && global.te(error)) {
                notificationStore.showNotification({
                  type: 'error',
                  message: global.t(error),
                })
              } else {
                handleError(err)
              }
              reject(err)
            })
        })
      },

      fetchTransactions(params) {
        return new Promise((resolve, reject) => {
          axios
            .get('/api/v1/bank-transactions', { params })
            .then((response) => {
              this.transactions = response.data.data
              this.reviewCount = response.data.meta.review_count
              resolve(response)
            })
            .catch((err) => {
              handleError(err)
              reject(err)
            })
        })
      },

      linkTransaction(id, invoiceIds) {
        const notificationStore = useNotificationStore()

        return new Promise((resolve, reject) => {
          axios
            .post(`/api/v1/bank-transactions/${id}/link`, {
              invoice_ids: invoiceIds,
            })
            .then((response) => {
              notificationStore.showNotification({
                type: 'success',
                message: global.t('bank_transactions.linked_message'),
              })
              resolve(response)
            })
            .catch((err) => {
              handleError(err)
              reject(err)
            })
        })
      },

      ignoreTransaction(id) {
        const notificationStore = useNotificationStore()

        return new Promise((resolve, reject) => {
          axios
            .post(`/api/v1/bank-transactions/${id}/ignore`)
            .then((response) => {
              notificationStore.showNotification({
                type: 'success',
                message: global.t('bank_transactions.ignored_message'),
              })
              resolve(response)
            })
            .catch((err) => {
              handleError(err)
              reject(err)
            })
        })
      },
    },
  })()
}
