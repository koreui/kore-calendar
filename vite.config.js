import { defineConfig } from 'vite'

export default defineConfig({
    build: {
        lib: {
            entry: 'resources/js/index.js',
            name: 'KoreCalendar',
            formats: ['iife'],
            fileName: () => 'kore-calendar.js',
        },
        outDir: 'dist',
        emptyOutDir: true,
    },
})
