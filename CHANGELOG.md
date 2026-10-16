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
- **Motor de layout (fase 3).** Port a PHP del kernel de colocación de FullCalendar v7.1.3 (MIT, aviso en `THIRD-PARTY-NOTICES.md`): niveles, reparto de excedentes con pila iterativa, trozos de eventos largos (`eventSlicing`), "+N más" por celda y reparto horizontal por componente conexa en la rejilla horaria. Sus tests portados como fixtures (#5883, #5790, #7447, #7914, #7879…) y las invariantes de `kernel-fuzz` con 300 semillas. El modo "auto" por píxeles no se porta: el servidor no mide.
- **Vistas mes, semana, día, agenda y columnas por recurso (fase 3).** `EventOrder` (`start,-duration,allDay,title`, nulos al final en ambas direcciones, texto con Collator), cortes por día (`DaySlicer`, con `nextDayThreshold` y días ocultos) y por columna horaria (`TimeSlicer`, con ejes de más de 24 h), y los layouts listos para Blade. Las columnas por recurso admiten cabecera fecha → recursos o recurso → fechas; una barra de varios días se parte cuando sus columnas no son contiguas.
- **Accesibilidad de las vistas.** `role=table` con cada evento en la lista de la celda donde empieza, `aria-current="date"`, una sola parada de tabulación por rejilla y flechas (←/→ día, ↑/↓ semana, Inicio/Fin, invertidas en RTL), popover "+N más" como `role=dialog` con foco atrapado que devuelve el foco al cerrar.
- **Colores de evento como tokens** (`--kore-event: var(--kore-chart-3)`) con texto en el primer plano del tema sobre un tinte, para no depender del contraste del color de categoría. Un color que no parece CSS se descarta: acaba en un atributo `style`.
- **Fechas localizadas con `isoFormat`** (`LL`, `LT`…): sin ext-intl y sin preposiciones de un idioma escritas a mano en los formatos.
- **Prueba técnica de arrastre** (`<x-kore::calendar.spike-week>`): posiciones calculadas en PHP, previsualización en Alpine y un solo viaje a Livewire al soltar. Validada en el demo con cuatro re-renders del servidor en mitad del gesto, sin perderlo. Se retira con la vista semana de la fase 3.
