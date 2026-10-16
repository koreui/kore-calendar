# Calendar

Calendario de eventos y recursos: vistas mes, semana, día, agenda, columnas por recurso y timeline. El layout se calcula en PHP (con el algoritmo de colocación de FullCalendar portado) y se pinta en Blade con los tokens de kore-ui, así que el tema y el dark mode se aplican solos. **Sin dependencias JavaScript**: el bundle propio pesa menos de 5 kB gzip y gestiona el teclado, el arrastre y la selección.

Documentación: **Calendar** · [KoreCalendar](kore-calendar.md) · [Configuración](configuration.md) · [Theming](theming.md) · [Accesibilidad](accessibility.md) · [iCalendar](ical.md)

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

El `@import` va después del tema de kore-ui. El JavaScript lo carga el propio componente cuando aparece (con `@assets`): no hay directiva que añadir al layout. El formulario de evento, el diálogo de series y los avisos necesitan además `<livewire:kore-overlay-manager />` y `<livewire:kore-feedback-manager />` en el layout, como cualquier overlay de kore-ui.

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

Los eventos pueden ser arrays, `CalendarEvent` o fuentes (`EventSource`: `EloquentSource`, `ArraySource`, `CallbackSource`, `ICalSource`, o `Meeting::calendarSource()` si el modelo usa `HasCalendarEvents`), y se pueden mezclar en la misma lista.

## Props del componente

| Prop | Tipo | Default | Descripción |
|------|------|---------|-------------|
| `events` | `iterable` | `[]` | Eventos (arrays, `CalendarEvent`) o fuentes (`EventSource`) |
| `resources` | `iterable\|null` | `null` | Recursos. Sin ellos no se ofrecen las vistas por recurso |
| `view` | `string` | config `default_view`, o la primera de `views` | `month`, `week`, `day`, `list`, `resource-week`, `resource-day`, `timeline-day`, `timeline-week`, `timeline-month`. `?view=` la sustituye. Sin ninguna de las dos, `default_view` del config y, si no está entre las vistas del selector, la primera de `views` |
| `date` | `string\|DateTime` | hoy | Fecha que se muestra. `?date=YYYY-MM-DD` la sustituye |
| `views` | `array` | `config(views)` | Vistas del selector |
| `timezone` | `string` | `app.timezone` | Zona de la vista |
| `locale` | `string` | `app()->getLocale()` | Idioma de los textos y las fechas (`en` o `es`) |
| `first-day` | `int` | datepicker de kore-ui | 0 = domingo |
| `hidden-days` | `array` | `[]` | Días ocultos (`[0, 6]` = laborables) |
| `week-numbers` | `false\|'iso'\|'locale'` | `false` | Números de semana en la vista mes |
| `slot-duration` | `string` | `30m` | Rejilla horaria |
| `slot-min-time` / `slot-max-time` | `string` | `00:00` / `24:00` | Banda visible; admite `-02:00` y `26:00` |
| `max-events` | `int\|null` | `null` | Límite por celda en el mes; el resto va al "+N más" |
| `now-indicator` | `bool` | `true` | Línea de "ahora" en la rejilla horaria |
| `business-hours` | `bool\|array` | `false` | Horario laboral: pinta lo que no lo es ([ver](#horario-laboral-y-disponibilidad)) |
| `highlight-conflicts` | `bool` | `false` | Marca los eventos que se solapan en el mismo recurso |
| `toolbar` | `bool` | `true` | Barra con navegación y selector de vista |
| `mobile-view` | `string` | config `mobile_view` | Vista que se abre en una pantalla estrecha la primera vez, si la URL no trae `?view=` ([ver](configuration.md#pantallas-estrechas)) |
| `date-param` / `view-param` | `string` | `date` / `view` | Nombres de los parámetros de la URL |

Cada prop gana a la clave del config del mismo nombre. Las demás claves, y la lista completa con tipos y defaults, están en [Configuración](configuration.md#props-de-x-korecalendar). Los atributos que no son props (`class`, `id`, `data-*`) van al `div` que envuelve el calendario.

## Eventos

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | `string\|int` | Obligatorio |
| `title` | `string` | Obligatorio |
| `start`, `end` | `string\|DateTime` | `end` es **exclusivo** y opcional. `'2026-10-14'` → `'2026-10-17'` cubre del 14 al 16 |
| `allDay` | `bool\|null` | Sin indicar: día completo si `start` (y `end`) son fechas sin hora |
| `resourceIds` / `resourceId` | `array` / `string` | Recursos del evento |
| `color` | `string` | Token de kore-ui (`primary`, `secondary`, `accent`, `success`, `warning`, `destructive`, `info`, `muted`, `chart-1`…`chart-8`) o color CSS. Ver [Theming](theming.md#color-de-los-eventos) |
| `url` | `string` | El evento es un enlace. Solo URL relativas o con esquema `http`, `https`, `mailto` o `tel`; otra cosa (`javascript:`, `data:`) lanza una `InvalidArgumentException` |
| `display` | `string` | `auto`, `block`, `list-item`, `background`, `none` |
| `groupId`, `editable`, `startEditable`, `durationEditable`, `overlap` | | Para la interacción en `KoreCalendar`: ganan a la configuración del calendario ([Edición](kore-calendar.md#edición-mover-redimensionar-y-seleccionar)) |
| `rrule`, `exdates`, `recurrenceOf`, `recurrenceId` | | Eventos que se repiten: ver [Eventos recurrentes](#eventos-recurrentes) |
| `meta` | `array` | Datos libres. **Cualquier otra clave lanza una excepción**: una errata como `colour` no se pierde en silencio |

Un string sin zona (`'2026-10-15 10:00'`) es hora de pared en la zona de la vista. Una fecha con zona o con offset conserva su instante y se muestra en la zona de la vista. Un evento sin fin, o con un fin anterior al inicio, no se descarta: recibe la duración por defecto (`time.default_timed_duration`).

## Eventos recurrentes

Un evento con `rrule` es una **serie**: el calendario genera sus ocurrencias dentro del rango que dibuja (con [`rlanvin/php-rrule`](https://github.com/rlanvin/php-rrule)), y cada una es un evento más para las vistas.

```php
[
    'id' => 'standup', 'title' => 'Standup',
    'start' => '2026-10-12 09:00', 'end' => '2026-10-12 09:30',   // la PRIMERA ocurrencia: de ahí sale la duración
    'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231',
    'exdates' => ['2026-10-14 09:00:00'],                          // ocurrencias que NO se generan
]
```

| Campo | |
|---|---|
| `rrule` | Regla RFC 5545 **sin** `DTSTART` ni el prefijo `RRULE:`: el inicio de la serie es `start`. Con uno de los dos lanza una excepción, y también una regla que php-rrule no entiende |
| `exdates` | Fechas excluidas, en la misma forma que `start` (ver «Zonas»). Una fecha sin hora (`'2026-10-14'`) excluye la ocurrencia de ese día, sea a la hora que sea |
| `recurrenceOf`, `recurrenceId` | Una **excepción**: la ocurrencia de la serie `recurrenceOf` que empezaba en `recurrenceId` (su inicio ORIGINAL) y ahora es este evento, con otro título, hora o recurso. Hacen falta las dos |

- **Una excepción sustituye a la ocurrencia, no se suma.** El colector la reconoce por `recurrenceId`, también si llega de otra fuente o antes que su serie. Es un evento normal con su propio `id`; no puede tener `rrule`.
- **Zonas: flotante o con zona.** Es lo que más ha dado que hacer en otros calendarios (FullCalendar #5993 y #5726), así que lo decide `start`:
  - `start` como string **sin zona** (`'2026-10-12 09:00'`) o de día completo → regla **flotante**: «cada lunes a las 9» es a las 9 de la zona de la vista, también tras el cambio de hora.
  - `start` como fecha **con zona** (un `Carbon` de Eloquent, o `'2026-10-12T09:00:00+02:00'`) → la regla corre **en esa zona** y cada ocurrencia se convierte a la vista. «Las 9 de Madrid» vistas desde Nueva York salen a las 3 o a las 4 según la semana, porque los dos países cambian de hora en fechas distintas.
  - Los `exdates` y los `recurrenceId` se comparan con la ocurrencia **en la misma representación** (una hora de pared, o un instante), no como texto: un `exdate` con `Z` excluye en una serie con zona. En una flotante, una hora con zona se lleva a la hora de pared de la vista.
- **`UNTIL`.** RFC 5545 lo exige en UTC cuando el inicio lleva hora, pero lo natural es escribir `UNTIL=20261231` («hasta ese día»), y se acepta: una fecha sin hora es hasta el final de ese día, inclusive; una hora sin `Z` (`20261231T100000`) es de pared (flotante) o de la zona de la serie; con `Z` es UTC.
- **Una ocurrencia que empezó antes de la vista y entra en ella también se dibuja** (un evento de dos días que cruza el borde).
- **Topes.** La expansión empieza en la vista, no en el primer `start` de la serie, así que una serie diaria de hace diez años cuesta lo mismo que una de hoy. Además hay topes de ocurrencias dibujadas y recorridas, por serie y por render (`recurrence.max_*`, ver [configuration.md](configuration.md)): una regla hostil (`FREQ=SECONDLY` desde hace años) no agota la memoria. Pasado un tope se avisa en el log y se corta.
- **`id` compartido.** Todas las ocurrencias de una serie tienen el `id` de la serie; lo que las distingue es el `instanceId` (`"{id}:{inicio original}"`), que el calendario pinta en `data-kore-instance`.

Editar una ocurrencia pregunta a qué alcanza el cambio: [Series recurrentes](kore-calendar.md#series-recurrentes-editar-por-alcance).

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

Con recursos también hay **timeline**: el tiempo en horizontal y una fila por recurso, agrupadas por `group` (plegables) y en árbol por `parentId`. Las ranuras se configuran por vista en `timeline.slots`. Con más de `timeline.virtualize_after` filas, `KoreCalendar` solo monta una ventana y pide el resto al hacer scroll. `<x-kore::calendar>` no virtualiza: sin Livewire no hay servidor que dé más filas, así que pinta todas (con cientos de recursos y eventos conviene la clase base).

Cada recurso tiene `id`, `title` y, opcionales, `parentId` (árbol), `group`, `color` (por defecto para sus eventos), `order`, `businessHours` ([su horario laboral](#horario-laboral-y-disponibilidad)) y `meta`. Un evento sin recurso no aparece en las vistas por recurso. Los ids duplicados, los padres que no existen y los ciclos lanzan una excepción.

## Horario laboral y disponibilidad

`business-hours` (o `business_hours` en el config, o `businessHours()` en `configure()`) pinta con un fondo suave lo que **no** es horario laboral:

```blade
<x-kore::calendar view="week" :business-hours="true" />   {{-- lunes a viernes, 09:00-17:00 --}}

<x-kore::calendar view="week" :business-hours="[
    ['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '09:00', 'endTime' => '13:00'],
    ['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '15:00', 'endTime' => '19:00'],
]" />
```

| Valor | Significa |
|---|---|
| `true` | Lunes a viernes de 09:00 a 17:00, como FullCalendar |
| `false`, `null`, `[]` | Sin horario: no se pinta nada y todo vale (no es «cerrado») |
| Un tramo `['daysOfWeek' => [1, 2], 'startTime' => '08:00', 'endTime' => '14:00']` | Lo que falte toma el valor de `true` |
| Una lista de tramos | En una lista, cada tramo dice sus `daysOfWeek` (0 = domingo … 6 = sábado) |

- **`endTime` puede pasar de 24:00** (hasta `48:00`): `['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']` es un turno de noche del viernes al sábado a las 02:00, y la madrugada del sábado cuenta como horario.
- **Los tramos que se tocan o se solapan se funden en uno**: 09:00-13:00 y 13:00-17:00 son 09:00-17:00.
- **Horas de pared.** El horario no se mueve con el cambio de hora: las 09:00 son las 09:00 también el día en que el reloj se adelanta o se atrasa.
- **Un horario mal escrito lanza una excepción** (clave desconocida, día fuera de 0-6, hora que no es `HH:MM`, fin anterior al inicio), con el tramo y la clave en el mensaje.

Dónde se pinta, siempre con elementos decorativos (`aria-hidden`: no se leen, la información accesible es la validación del servidor):

- **Semana, día y recursos**: un bloque por cada tramo no laborable de cada columna.
- **Timeline**: igual, por fila. Con ranuras de días (`timeline-week`, `timeline-month`) solo se atenúan los días enteros sin ningún tramo.
- **Mes**: los días sin ningún tramo se atenúan.
- **Agenda**: nada.

El color es el token `--kore-calendar-non-business`, derivado de `--kore-muted` y redefinido en oscuro; se puede sobrescribir como cualquier otro token.

**Horario por recurso.** El `businessHours` de un recurso gana al del calendario para sus columnas o filas, con la misma forma. `false` (o `[]`) lo deja siempre abierto aunque el calendario tenga horario; `null` (por defecto) hereda el del calendario:

```php
protected function resources(): iterable
{
    return [
        ['id' => 'sala-a', 'title' => 'Sala A', 'businessHours' => ['startTime' => '14:00', 'endTime' => '20:00']],
        ['id' => 'sala-b', 'title' => 'Sala B'],                              // el del calendario
        ['id' => 'maquina', 'title' => 'Máquina', 'businessHours' => false],  // siempre abierta
    ];
}
```

**Conflictos.** Con `highlight-conflicts` (`highlightConflicts()` en la clase base) los eventos que se solapan en el mismo recurso (los que no tienen ninguno, entre sí) llevan un icono, un borde y un texto solo para lectores de pantalla («En conflicto con «Reunión B»», traducción `conflict`; con más de tres conflictos nombra tres y cuenta el resto: «… y 4 más»). Un evento de fondo no cuenta salvo que diga `overlap: false`, y los del mismo `groupId` no chocan entre sí. El aviso es del evento, no de la columna.

Las restricciones (que un evento movido o una selección deban quedar en horario laboral) y la búsqueda de huecos libres están en [KoreCalendar](kore-calendar.md#horario-laboral-y-disponibilidad).

## Configuración

`php artisan vendor:publish --tag=kore-calendar-config` publica `config/kore-calendar.php`: vista por defecto, zona, primer día, rejilla horaria, límites del mes, orden de eventos (`event_order`, el mismo formato que FullCalendar), formatos de fecha (`isoFormat` de Carbon), idioma (`locale`) y los textos de interfaz que cambias (`translations`; los de serie están en `lang/{en,es}`). Todas las claves, los métodos de `configure()` y las props equivalentes: [Configuración](configuration.md). Los colores y tamaños: [Theming](theming.md).

Al publicar el config puedes dejar solo lo que cambias: los bloques se funden con los del paquete a cualquier profundidad (un `formats` con un solo formato conserva los demás) y las listas (`views`, `hidden_days`, los tramos de `business_hours`) se sustituyen enteras. `php artisan vendor:publish --tag=kore-calendar-views` copia las vistas a `resources/views/vendor/kore-calendar`, y las copias ganan a las del paquete (ver [Configuración](configuration.md#cómo-se-resuelve-un-valor)).

## Accesibilidad

- La rejilla es un `role=table` (no `grid`, para que los lectores de pantalla sigan en modo lectura), con cada evento en la lista de la celda donde empieza.
- La rejilla es **una sola parada de tabulación**. En el mes, las flechas mueven por días (←/→) y semanas (↑/↓), e Inicio/Fin van a los extremos de la semana; en semana, día y recursos se enfocan las cabeceras de columna (←/→, Inicio/Fin). En RTL se invierten.
- Hoy lleva `aria-current="date"`. Las cabeceras dicen la fecha completa al lector de pantalla.
- El título se anuncia al navegar (`aria-live="polite"`).
- El "+N más" abre un `role=dialog` con el foco atrapado; Escape lo cierra y devuelve el foco al botón.
- Lo no laborable es decoración (`aria-hidden`). Un evento en conflicto lleva un texto para lectores de pantalla además del icono, y un movimiento fuera de horario se rechaza con un toast que dice el motivo.

Mover y redimensionar con el teclado, los anuncios, el comportamiento táctil y lo que todavía no está cubierto: [Accesibilidad](accessibility.md).

## Notas de implementación

- **Zonas horarias y cambios de hora.** La rejilla trabaja en horas de pared, donde un día mide siempre 24 h. Al volver a un instante, una hora que se repite (otoño) es la primera ocurrencia y una que no existe (primavera) se resuelve hacia delante. PHP no hace esto de forma consistente entre zonas, así que lo hace el paquete.
- **El límite de eventos es un número.** El modo "auto" de FullCalendar mide alturas en el navegador; el servidor no puede.
- **Los eventos se piden con el rango dibujado** (con los días de relleno del mes), no con el del mes: si no, desaparecen eventos de la primera y la última semana.

## iCalendar

Exportar e importar `.ics` (Google Calendar, Outlook, Apple): ver [ical.md](ical.md).
