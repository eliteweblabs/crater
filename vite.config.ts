import { defineConfig } from 'laravel-vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    server: {
        watch: {
            ignored: ['**/.env/**'],
        },
    },
    build: {
        target: 'es2020', // Support BigInt literals
        minify: 'terser',
    },
    resolve: {
        alias: {
            "vue-i18n": "vue-i18n/dist/vue-i18n.cjs.js"
        }
    }
}).withPlugins(
    vue
)
