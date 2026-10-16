# Calendar

Calendario de eventos y recursos: vistas mes, semana, día, agenda, columnas por recurso y timeline. El layout se calcula en PHP (con el algoritmo de colocación de FullCalendar portado) y se pinta en Blade con los tokens de kore-ui, así que el tema y el dark mode se aplican solos. **Sin dependencias JavaScript**: el bundle del calendario pesa 1,5 kB gzip y solo gestiona el teclado.

Hay dos formas de usarlo, igual que el DataTable y el Kanban:

1. **Clase base `KoreCalendar`**: un componente Livewire con estado, eventos desde tu base de datos y navegación sin recargar la página (recomendado). Ver [kore-calendar.md](kore-calendar.md).
2. **Componente `<x-kore::calendar>`**: en cualquier página Blade, sin Livewire. La navegación son enlaces con `?date=` y `?view=`.

## Instalación

```bash
composer require kore-ui/calendar
```

En `resources/css/app.css`, junto a lo de kore-ui:

```css
@import '../../vendor/kore-ui/calendar/resources/css/kore-calendar.css';
@source '../../vendor/kore-ui/calendar/resources/**/*.blade.php';
@source '../../vendor/kore-ui/calendar/src/**/*.php';
```

El JavaScript lo carga el propio componente cuando aparece (con `@assets`). No hay directiva que añadir al layout.

## Componente

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

| Prop | Tipo | Default | Descripción |
|------|------|---------|-------------|
| `events` | `iterable` | `[]` | Eventos (arrays, `CalendarEvent`) o fuentes (`EventSource`) |
| `resources` | `iterable\|null` | `null` | Recursos. Sin ellos no se ofrecen las vistas por recurso |
| `view` | `string` | `config(default_view)` | `month`, `week`, `day`, `list`, `resource-week`, `resource-day`, `timeline-day`, `timeline-week`, `timeline-month` |
| `date` | `string\|DateTime` | hoy | Fecha que se muestra |
| `views` | `array` | `config(views)` | Vistas del selector |
| `timezone` | `string` | `app.timezone` | Zona de la vista |
| `locale` | `string` | `app()->getLocale()` | Idioma de fechas |
| `first-day` | `int` | datepicker de kore-ui | 0 = domingo |
| `hidden-days` | `array` | `[]` | Días ocultos (`[0, 6]` = laborables) |
| `week-numbers` | `false\|'iso'\|'locale'` | `false` | Números de semana en la vista mes |
| `slot-duration` | `string` | `30m` | Rejilla horaria |
| `slot-min-time` / `slot-max-time` | `string` | `00:00` / `24:00` | Banda visible; admite `-02:00` y `26:00` |
| `max-events` | `int\|null` | `null` | Límite por celda en el mes; el resto va al "+N más" |
| `now-indicator` | `bool` | `true` | Línea de "ahora" en la rejilla horaria |
| `toolbar` | `bool` | `true` | Barra con navegación y selector de vista |
| `date-param` / `view-param` | `string` | `date` / `view` | Nombres de los parámetros de la URL |

## Eventos

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | `string\|int` | Obligatorio |
| `title` | `string` | Obligatorio |
| `start`, `end` | `string\|DateTime` | `end` es **exclusivo** y opcional. `'2026-10-14'` → `'2026-10-17'` cubre del 14 al 16 |
| `allDay` | `bool\|null` | Sin indicar: día completo si `start` (y `end`) son fechas sin hora |
| `resourceIds` / `resourceId` | `array` / `string` | Recursos del evento |
| `color` | `string` | Token (`primary`, `success`, `chart-1`…`chart-8`) o color CSS |
| `url` | `string` | El evento es un enlace |
| `display` | `string` | `auto`, `block`, `list-item`, `background`, `none` |
| `groupId`, `editable`, `startEditable`, `durationEditable`, `overlap` | | Para la interacción (fases siguientes) |
| `meta` | `array` | Datos libres. **Cualquier otra clave lanza una excepción**: una errata como `colour` no se pierde en silencio |

Un string sin zona (`'2026-10-15 10:00'`) es hora de pared en la zona de la vista. Una fecha con zona o con offset conserva su instante y se muestra en la zona de la vista. Un evento sin fin, o con un fin anterior al inicio, no se descarta: recibe la duración por defecto (`time.default_timed_duration`).

## Recursos

```blade
<x-kore::calendar
    view="resource-day"
    :resources="[
        ['id' => 'ambar', 'title' => 'Sala Ámbar', 'color' => 'chart-1'],
        ['id' => 'bruma', 'title' => 'Sala Bruma'],
    ]"
    :events="$reservas"
/>
```

Con recursos también hay **timeline**: el tiempo en horizontal y una fila por recurso, agrupadas por `group` (plegables) y en árbol por `parentId`. Las ranuras se configuran por vista en `timeline.slots`. Con más de `timeline.virtualize_after` filas, dentro de `KoreCalendar` solo se monta una ventana y el resto se pide al hacer scroll.

Cada recurso tiene `id`, `title` y, opcionales, `parentId` (árbol), `group`, `color` (por defecto para sus eventos), `order`, `businessHours` y `meta`. Un evento sin recurso no aparece en las vistas por recurso. Los ids duplicados, los padres que no existen y los ciclos lanzan una excepción.

## Configuración

`php artisan vendor:publish --tag=kore-calendar-config` publica `config/kore-calendar.php`: vista por defecto, zona, primer día, rejilla horaria, límites del mes, orden de eventos (`event_order`, el mismo formato que FullCalendar), formatos de fecha (`isoFormat` de Carbon) y textos de interfaz (`translations`).

## Accesibilidad

- La rejilla es un `role=table` (no `grid`, para que los lectores de pantalla sigan en modo lectura), con cada evento en la lista de la celda donde empieza.
- La rejilla es **una sola parada de tabulación**. Dentro, las flechas mueven por días (←/→) y semanas (↑/↓), e Inicio/Fin van a los extremos de la semana. En RTL se invierten.
- Hoy lleva `aria-current="date"`. Las cabeceras dicen la fecha completa al lector de pantalla.
- El título se anuncia al navegar (`aria-live="polite"`).
- El "+N más" abre un `role=dialog` con el foco atrapado; Escape lo cierra y devuelve el foco al botón.

## Notas de implementación

- **Zonas horarias y cambios de hora.** La rejilla trabaja en horas de pared, donde un día mide siempre 24 h. Al volver a un instante, una hora que se repite (otoño) es la primera ocurrencia y una que no existe (primavera) se resuelve hacia delante. PHP no hace esto de forma consistente entre zonas, así que lo hace el paquete.
- **El límite de eventos es un número.** El modo "auto" de FullCalendar mide alturas en el navegador; el servidor no puede.
- **Los eventos se piden con el rango dibujado** (con los días de relleno del mes), no con el del mes: si no, desaparecen eventos de la primera y la última semana.
