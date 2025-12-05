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
            // Remove vue-i18n alias - let it resolve naturally
        }
    }
}).withPlugins(
    vue
)
