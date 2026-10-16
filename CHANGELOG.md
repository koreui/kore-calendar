# Changelog

Todos los cambios notables en este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/),
y el proyecto usa [Semantic Versioning](https://semver.org/lang/es/).

---

## [Unreleased]

### Added

- **Esqueleto del paquete (fase 1).** ServiceProvider con los componentes anónimos bajo el prefijo `kore` (`<x-kore::calendar.*>`, sin tapar los de kore-ui), bundle propio `dist/kore-calendar.js` servido con huella y cargado con `@assets`, presupuesto de 15 kB gzip, comprobación de sincronía del `dist/` y CI con la misma matriz que kore-ui.
- **Prueba técnica de arrastre** (`<x-kore::calendar.spike-week>`): posiciones calculadas en PHP, previsualización en Alpine y un solo viaje a Livewire al soltar. Validada en el demo con cuatro re-renders del servidor en mitad del gesto, sin perderlo. Se retira con la vista semana de la fase 3.
