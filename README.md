# kore-ui/calendar

Calendario de eventos y recursos para Laravel + Livewire 4, construido sobre [kore-ui](../kore-ui).

> **Estado:** fase 1 de 10 (esqueleto y prueba técnica). Todavía no es usable. Diseño completo en [`docs/calendar.md`](../docs/calendar.md) del monorepo.

- Layout calculado en PHP y renderizado en Blade; Alpine solo para arrastrar, redimensionar y seleccionar.
- Sin dependencias JavaScript de terceros.
- Vistas mes, semana, día, agenda, columnas por recurso y timeline.
- Temas con los tokens `--kore-*` de kore-ui, dark mode incluido.

## Instalación (cuando haya release)

```bash
composer require kore-ui/calendar
```

```css
/* resources/css/app.css, junto a lo de kore-ui */
@source '../../vendor/kore-ui/calendar/resources/**/*.blade.php';
@source '../../vendor/kore-ui/calendar/src/**/*.php';
```

El JavaScript lo carga el propio componente: no hay directiva que añadir al layout.

## Desarrollo

```bash
composer install && npm install
./vendor/bin/pest        # PHP
npm run test:js          # JS
npm run build            # dist/kore-calendar.js (se versiona)
npm run size             # presupuesto del bundle
```
