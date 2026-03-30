import { defineConfig } from 'laravel-vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    server: {
        watch: {
            ignored: ['**/.env/**'],
        },
    },
    build: {
        target: 'es2020', // Support BigInt literals
        minify: 'terser',
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/scripts/main.js')
        }
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources'),
        },
    },
    optimizeDeps: {
        include: [
            'vue',
            'vue-router',
            'pinia',
            '@vuelidate/core',
            'vue-i18n'
        ]
    }
}).withPlugins(
    vue
)
