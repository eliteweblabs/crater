import { handleError } from '@/scripts/customer/helpers/error-handling'
import { useUserStore } from './user'
const { defineStore } = window.pinia
import axios from 'axios'
export const useGlobalStore = defineStore({
  id: 'CustomerPortalGlobalStore',
  state: () => ({
    languages: [],
    currency: null,
    isAppLoaded: false,
    countries: [],
    getDashboardDataLoaded: false,
    currentUser: null,
    companySlug: '',
    mainMenu: null,
    enabledModules: []
  }),

  actions: {
    bootstrap(data) {
      this.companySlug = data
      const userStore = useUserStore()
      return new Promise((resolve, reject) => {
        axios
          .get(`/api/v1/${data}/customer/bootstrap`)
          .then((response) => {
            this.currentUser = response.data.data
            this.mainMenu = response.data.meta.menu
            this.currency = response.data.data.currency
            this.enabledModules = response.data.meta.modules
            Object.assign(userStore.userForm, response.data.data)
            window.i18n.locale = response.data.default_language
            this.isAppLoaded = true
            resolve(response)
          })
          .catch((err) => {
            // If 401 and we're on a payment route, don't show error - just allow redirect
            if (err.response && err.response.status === 401) {
              const currentPath = window.location.pathname
              // If we're about to pay or on a public invoice page, allow it
              if (currentPath.includes('/pay') || currentPath.includes('/invoices/view/')) {
                // Silently fail - payment route doesn't need auth
                this.isAppLoaded = true
                resolve({ data: { data: null, meta: { menu: [], modules: [] } } })
                return
              }
            }
            handleError(err)
            reject(err)
          })
      })
    },

    fetchCountries() {
      return new Promise((resolve, reject) => {
        if (this.countries.length) {
          resolve(this.countries)
        } else {
          axios
            .get(`/api/v1/${this.companySlug}/customer/countries`)
            .then((response) => {
              this.countries = response.data.data
              resolve(response)
            })
            .catch((err) => {
              handleError(err)
              reject(err)
            })
        }
      })
    },
  },
})
