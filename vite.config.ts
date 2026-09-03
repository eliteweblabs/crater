import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    publicDir: path.resolve(__dirname, 'resources/static'),
    server: {
        watch: {
            ignored: ['**/.env/**'],
        },
    },
    build: {
        manifest: true,
        outDir: 'public/build',
        target: 'es2020', // Support BigInt literals
        minify: 'terser',
        rollupOptions: {
            input: path.resolve(__dirname, 'resources/scripts/main.js'),
        },
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
            'vue-i18n',
        ],
    },
    plugins: [vue()],
})
