# Changelog

Todos los cambios notables en este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/),
y el proyecto usa [Semantic Versioning](https://semver.org/lang/es/).

---

## [Unreleased]

### Added

- **Esqueleto del paquete (fase 1).** ServiceProvider con los componentes anónimos bajo el prefijo `kore` (`<x-kore::calendar.*>`, sin tapar los de kore-ui), bundle propio `dist/kore-calendar.js` servido con huella y cargado con `@assets`, presupuesto de 15 kB gzip, comprobación de sincronía del `dist/` y CI con la misma matriz que kore-ui.
- **Modelo de tiempo (fase 2).** `Marker` (hora de pared con días de 24 h, el modelo de FullCalendar), `WallClock` (instante ↔ hora de pared), `DateRange` semiabierto, `WeekRules` (primer día y numeración ISO o por locale, en paridad con PHP para cada día de 2000 a 2040) y `ViewRange` (rangos actual, dibujado y activo de cada vista, con días ocultos y navegación que trunca antes de sumar).
- **Las horas repetidas del cambio de horario se resuelven siempre a la primera ocurrencia.** PHP no es consistente: en Europe/Madrid `2026-10-25 02:30` da la segunda (+01:00) y en America/New_York `2024-11-03 01:30` la primera. `WallClock::toInstant()` aplica la regla él mismo; las horas que no existen van hacia delante.
- **Eventos, recursos y fuentes (fase 2).** `CalendarEvent` (claves desconocidas lanzan excepción en vez de perderse), `EventNormalizer` (inferencia de día completo, fin exclusivo, fin ausente o inválido con duración por defecto y nunca un rango vacío, también en el pliegue de otoño), fuentes `ArraySource`, `CallbackSource` y `EloquentSource` + trait `HasCalendarEvents`, `EventCollector`, `CalendarResource` y `ResourceSet` (orden, árbol, grupos; ids duplicados, huérfanos y ciclos se rechazan).
- **Prueba técnica de arrastre** (`<x-kore::calendar.spike-week>`): posiciones calculadas en PHP, previsualización en Alpine y un solo viaje a Livewire al soltar. Validada en el demo con cuatro re-renders del servidor en mitad del gesto, sin perderlo. Se retira con la vista semana de la fase 3.
