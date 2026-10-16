# kore-ui/calendar

Calendario de eventos y recursos para Laravel + Livewire 4, construido sobre [kore-ui](../kore-ui): vistas mes, semana, día, agenda, columnas por recurso y timeline; arrastrar, redimensionar y seleccionar; formulario de evento; series recurrentes; horario laboral y conflictos; exportar e importar `.ics`.

- El layout se calcula en PHP y se pinta en Blade. Alpine solo previsualiza los gestos; lo que manda el navegador se vuelve a validar en el servidor.
- Sin dependencias JavaScript de terceros: el bundle propio pesa menos de 5 kB gzip.
- Temas con los tokens `--kore-*` de kore-ui, dark mode incluido.
- English and Spanish: textos y fechas en el idioma de la aplicación (o el que configures), con `lang/` publicable. Ver [Idioma y traducciones](docs/configuration.md#idioma-y-traducciones).
- Se usa de dos formas: el componente `<x-kore::calendar>` en cualquier página Blade (solo lectura, navegación con enlaces) o la clase base `KoreCalendar`, un componente Livewire con estado, edición y eventos desde tu base de datos.

> **Estado:** pre-release (`0.x`, sin versión publicada). La API puede cambiar hasta la primera release; los cambios están en [CHANGELOG.md](CHANGELOG.md).

## Requisitos

| | Versión |
|---|---|
| PHP | 8.2 o superior (la CI prueba 8.2 a 8.4; Laravel 13 no se prueba en 8.2) |
| Laravel | 12 o 13 |
| Livewire | 4 |
| [kore-ui](../kore-ui) (`kore-ui/kore-ui`) | 2.3 o superior, con su layout configurado (`@koreScripts`, tema, Tailwind v4) |

`nesbot/carbon` 3 y `rlanvin/php-rrule` 2.5 se instalan solos con Composer. `ext-intl` es opcional: sin ella las fechas salen igual, y con ella el orden alfabético de títulos y recursos respeta el locale.

## Instalación

```bash
composer require kore-ui/calendar
```

Sin release publicada, en el monorepo se instala como path repository, igual que kore-ui (`"kore-ui/calendar": "@dev"` y un repositorio `{"type": "path", "url": "../kore-calendar"}` en `composer.json`, como en `demo/`).

**CSS.** En `resources/css/app.css`, junto a lo de kore-ui:

```css
@import '../../vendor/kore-ui/calendar/resources/css/kore-calendar.css';

@source '../../vendor/kore-ui/calendar/resources/**/*.blade.php';
@source '../../vendor/kore-ui/calendar/src/**/*.php';
```

El `@import` va después del tema de kore-ui (`kore-theme.css`): ver [Theming](docs/theming.md).

**Layout.** Lo de kore-ui (`@koreScripts` antes de `@livewireScripts`) y, para el formulario de evento, el diálogo de series y los avisos, los dos gestores:

```blade
<livewire:kore-overlay-manager />
<livewire:kore-feedback-manager />
```

El JavaScript del calendario lo carga el propio componente cuando aparece (con `@assets`): no hay directiva que añadir.

**Config (opcional).**

```bash
php artisan vendor:publish --tag=kore-calendar-config   # solo lo que cambias: se funde con el del paquete
php artisan vendor:publish --tag=kore-calendar-views    # vistas (componentes y Livewire) para sobrescribirlas
```

## Ejemplo mínimo: componente

```blade
<x-kore::calendar
    :events="[
        ['id' => 1, 'title' => 'Congreso', 'start' => '2026-10-14', 'end' => '2026-10-17'],
        ['id' => 2, 'title' => 'Revisión', 'start' => '2026-10-15 10:00', 'end' => '2026-10-15 11:30', 'color' => 'chart-3'],
    ]"
    view="month"
    timezone="Europe/Madrid"
/>
```

## Ejemplo mínimo: `KoreCalendar`

```php
use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

class TeamCalendar extends KoreCalendar
{
    protected function configure(): void
    {
        $this->views(['month', 'week', 'list'])->timezone('Europe/Madrid');
    }

    protected function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return Meeting::query()
            ->where('team_id', auth()->user()->team_id)   // el scope del tenant va aquí
            ->overlapping($from, $to)->get()->map->toCalendarEvent();
    }
}
```

```blade
<livewire:team-calendar />
```

La vista y la fecha quedan en la URL (`?view=`, `?date=`). Para mover, redimensionar, crear y editar eventos, `->editable()->selectable()->eventForm()` y los hooks `persistMove()`, `persistResize()`, `createEvent()`…: ver [KoreCalendar](docs/kore-calendar.md).

**Seguridad, en corto.** `events()` es la frontera de acceso: mover, editar o eliminar un evento lo busca entre los que `events()` devuelve, así que lo que no devuelve no se puede tocar. Pon ahí el scope del tenant o del usuario, y vuelve a aplicarlo (más `$this->authorize()`) en los hooks de guardado, que reciben un id y no tu modelo. `events()`, `resources()`, `configure()` y los hooks son `protected`: Livewire deja llamar desde el navegador a cualquier método público. Detalle y ejemplos en [Seguridad](docs/kore-calendar.md#seguridad).

## Vistas

| Vista | Qué muestra | Necesita recursos |
|---|---|---|
| `month` | Un mes en semanas completas; los eventos como barras, con «+N más» por celda | No |
| `week` | Columnas por día, eje de horas y carril de todo el día | No |
| `day` | Lo mismo para un solo día | No |
| `list` | Agenda: los eventos de la semana agrupados por día | No |
| `resource-week` | Semana con una columna por recurso y día | Sí |
| `resource-day` | Un día con una columna por recurso | Sí |
| `timeline-day` | Recursos en filas, horas en horizontal | Sí |
| `timeline-week` | Recursos en filas, días de la semana en horizontal | Sí |
| `timeline-month` | Recursos en filas, días del mes en horizontal | Sí |

Las vistas por recurso solo se ofrecen en el selector si el calendario tiene recursos.

## Documentación

| Documento | Contenido |
|---|---|
| [Calendar](docs/getting-started.md) | El componente, eventos, recursos, recurrencia, horario laboral y conflictos |
| [KoreCalendar](docs/kore-calendar.md) | La clase base: configuración fluida, acciones, edición, formulario, deshacer, series, disponibilidad |
| [Configuración](docs/configuration.md) | Todas las claves del config, los métodos de `configure()`, las props y las traducciones |
| [Theming](docs/theming.md) | Tokens `--kore-calendar-*`, color de eventos, dark mode |
| [Accesibilidad](docs/accessibility.md) | Teclado, lectores de pantalla, táctil y lo que falta |
| [iCalendar](docs/ical.md) | Exportar e importar `.ics` |

## Diferencias con FullCalendar

El kernel de colocación es un port de FullCalendar v7.1.3 (MIT, aviso en [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)), pero el paquete no envuelve ni carga FullCalendar.

**Hace igual**

- La colocación de eventos: niveles, trozos de los eventos largos, «+N más» por celda y reparto horizontal por colisión en la rejilla horaria, con sus casos de prueba portados.
- El modelo de fechas: horas de pared con días de 24 h (los cambios de horario no rompen la rejilla), intervalos semiabiertos y fin exclusivo en los eventos de día completo.
- Muchas opciones con el mismo sentido: orden de eventos (`start,-duration,allDay,title`), `slotMinTime` / `slotMaxTime` (admiten más de 24 h), `hiddenDays`, `firstDay`, `weekNumbers`, `nextDayThreshold`, `slotEventOverlap`, `snapDuration` (aquí `snap`), `businessHours`, `validRange`, `editable` / `startEditable` / `durationEditable`, `overlap` y `groupId`.
- La semántica de accesibilidad: rejilla `role=table`, `aria-current="date"` y «+N más» como `role=dialog`.

**No hace**

- **El modo `auto` de `dayMaxEvents`.** Mide alturas en píxeles en el navegador y el servidor no mide. El límite es un número (`month.max_events`, `month.max_event_rows`).
- **Opciones que son funciones JavaScript** (`eventAllow`, `eventContent`, `eventClassNames`, los callbacks de `select` o `eventDrop`). Aquí los hooks son métodos PHP protegidos (`persistMove()`, `persistResize()`, `onDateSelect()`, `onEventClick()`, `createEvent()`…) que corren tras validar en el servidor, y el HTML del evento es el del paquete. `eventConstraint` y `selectConstraint` solo admiten `'businessHours'`; otra regla va en el hook.
- **Vistas a medida** (año, duraciones propias): hay las nueve de la tabla.
- **Arrastrar eventos desde fuera del calendario** y renderizar el evento con una plantilla propia.
- **Redimensionar desde el borde inicial** con el ratón: el asa está solo en el final.

**Distinto, a propósito**

- Una clave desconocida en un evento lanza una excepción, en vez de acabar en `extendedProps`: una errata como `colour` no se pierde. Los datos libres van en `meta`.
- `eventConstraint('businessHours')` sin horario definido deja pasar todo; FullCalendar rechaza todo.
- Mover, redimensionar y seleccionar funcionan con el teclado y con pulsación larga en táctil, y el calendario anuncia los cambios con `aria-live`.
- Las series recurrentes se editan por alcance (solo esta, esta y las siguientes, toda la serie) con `RecurrenceEditor`, que da lo que hay que guardar sin saber RFC 5545.

**Gratis aquí, de pago allí**

Las vistas de recursos y el timeline son Premium en FullCalendar (AGPLv3 o licencia comercial). Aquí son parte del paquete (MIT): columnas por recurso, timeline por horas, días o mes con filas agrupadas, en árbol, plegables y virtualizadas (en `KoreCalendar`), cambio de recurso arrastrando, horario laboral por recurso, búsqueda de huecos libres (`AvailabilityFinder`) y detección de conflictos.

## Desarrollo

```bash
composer install && npm install
./vendor/bin/pest        # PHP
npm run test:js          # JS
npm run build            # dist/kore-calendar.js (se versiona)
npm run size             # presupuesto del bundle
```

## Licencia

MIT. El código derivado de FullCalendar conserva su aviso en [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
