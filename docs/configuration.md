# Configuración

Referencia de todo lo que se puede configurar en kore-ui/calendar: las claves de `config/kore-calendar.php`, los métodos de `configure()` que las cambian en un calendario concreto, las props de `<x-kore::calendar>` y los textos de la interfaz, en inglés o en español.

Documentación: [Calendar](getting-started.md) · [KoreCalendar](kore-calendar.md) · **Configuración** · [Theming](theming.md) · [Accesibilidad](accessibility.md) · [iCalendar](ical.md)

## Cómo se resuelve un valor

Para cada ajuste manda, en este orden:

1. La **prop** de `<x-kore::calendar>` o el **método de `configure()`** de la clase `KoreCalendar`.
2. La clave de `config/kore-calendar.php`: el del paquete, con lo que la aplicación haya publicado encima.
3. El valor por defecto escrito en el código (el de las tablas de abajo).

Un `null` cuenta como «no indicado»: `->maxEvents(null)` o `:max-events="null"` dejan el valor del config, no lo anulan. Para apagar algo hay que pasar `false`, `0` o `[]` donde el tipo lo admita.

```bash
php artisan vendor:publish --tag=kore-calendar-config   # config/kore-calendar.php
php artisan vendor:publish --tag=kore-calendar-views    # resources/views/vendor/kore-calendar
php artisan vendor:publish --tag=kore-calendar-lang     # lang/vendor/kore-calendar (los textos, en/es)
```

- **El config publicado se funde con el del paquete a cualquier profundidad.** Basta con dejar en tu `config/kore-calendar.php` lo que cambias: un `time`, `formats`, `interaction`, `timeline.slots` o `interaction.valid_range` a medias conserva el resto de sus claves. Las **listas** no se funden, se sustituyen enteras: un `views` de dos vistas ofrece esas dos (no las dos primeras del paquete con las demás detrás), y lo mismo `hidden_days` o una lista de tramos de `business_hours`. Un bloque publicado vacío (`'formats' => []`) cuenta como «sin cambios». Con el config en caché (`config:cache`) no se vuelve a fundir: el caché ya trae el resultado, así que tras actualizar el paquete vuelve a ejecutar `php artisan config:cache`. Al actualizar, revisa el `CHANGELOG` por claves nuevas.
- **Las vistas publicadas ganan a las del paquete.** El tag `kore-calendar-views` deja dos carpetas en `resources/views/vendor/kore-calendar`, y las dos se leen: `components/calendar/*` (los `<x-kore::calendar.*>`: mes, rejilla horaria, timeline, evento, barra…) y `livewire/*` (`calendar`, `event-form`, `recurrence-scope`). Publica solo lo que vayas a cambiar y borra del resto las copias: una vista publicada no se actualiza con el paquete.
- **Dos formas de cambiar un calendario.** En `<x-kore::calendar>` solo hay las props de [la tabla de props](#props-de-x-korecalendar); en `KoreCalendar`, `configure()` con los métodos de [la tabla de métodos](#métodos-de-configure) y `set()` para cualquier otra clave. El resto de claves del config son globales de toda la aplicación.

## Claves de `config/kore-calendar.php`

Las claves anidadas se escriben con puntos (`time.slot_duration`), igual que en `set()`. Las duraciones usan el formato de `Charts\Duration` de kore-ui: `500ms`, `30s`, `15m`, `2h`, `1d`, `1w`, o un número (segundos). Las horas son `HH:MM` y admiten negativos (`-02:00`) y más de 24 h (`26:00`).

### Generales

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `locale` | `?string` | `null` | Idioma de los textos **y** de las fechas: `en` o `es` (una variante como `es_MX` o `en_GB` cuenta como su idioma base). `null` = `app()->getLocale()`. Ver [Idioma](#idioma-y-traducciones) |
| `fallback_locale` | `string` | `'en'` | Idioma que se usa cuando el pedido no es `en` ni `es` (una aplicación en francés, por ejemplo) |
| `default_view` | `string` | `'month'` | Vista inicial de `KoreCalendar` y de `<x-kore::calendar>` cuando la URL no trae `?view=` (o no es una de las disponibles) ni la etiqueta una `view`. Si ella tampoco está disponible (no está en `views`, o es de recursos y no hay recursos), la primera de `views`: ver la nota debajo |
| `views` | `list<string>` | las nueve | Vistas del selector y únicas que acepta `?view=`. Posibles: `month`, `week`, `day`, `list`, `resource-week`, `resource-day`, `timeline-day`, `timeline-week`, `timeline-month`. Las cinco últimas solo se ofrecen si el calendario tiene recursos |
| `timezone` | `?string` | `null` | Zona de la vista. `null` = `config('app.timezone')` (o `UTC` si no hay) |
| `first_day` | `?int` | `null` | Primer día de la semana, `0` = domingo … `6` = sábado. `null` = `kore-ui.form.datepicker.start_of_week` (`1` si esa clave no existe) |
| `week_numbers` | `false\|'iso'\|'locale'` | `false` | Números de semana en la vista mes. Con `true` se usa ISO |
| `hidden_days` | `list<int>` | `[]` | Días de la semana que no se dibujan (`[0, 6]` = solo laborables) |
| `event_order` | `string` | `'start,-duration,allDay,title'` | Prioridad de los eventos al colocarlos, con el formato de `eventOrder` de FullCalendar (`-` invierte) |
| `event_order_strict` | `bool` | `false` | Colocación estricta: cada evento va por debajo del último con el que se solapa, sin rellenar huecos de los niveles de arriba |
| `now_indicator` | `bool` | `true` | Línea de «ahora» en la rejilla horaria y el timeline |
| `business_hours` | `bool\|array` | `false` | Horario laboral que se pinta y que pueden exigir `event_constraint` y `select_constraint`. `true` = lunes a viernes de 09:00 a 17:00; un tramo o una lista de tramos `['daysOfWeek' => [1, 2], 'startTime' => '08:00', 'endTime' => '14:00']` (`endTime` hasta `48:00`). Ver [Horario laboral](getting-started.md#horario-laboral-y-disponibilidad) |
| `highlight_conflicts` | `bool` | `false` | Marca con icono, borde y texto para lectores de pantalla los eventos que se solapan en el mismo recurso |

> **Qué vista se abre.** `KoreCalendar` y `<x-kore::calendar>` resuelven la vista inicial con la misma cadena y se quedan con el primer eslabón que el calendario ofrece: `?view=` de la URL (en el componente, solo si la barra está activa; en `KoreCalendar`, también lo que dejó `changeView()`), la prop `view` (solo el componente), `default_view` y, por último, la primera de `views`. Un eslabón que no está en `views` (o es de recursos sin recursos) se salta, no se pinta a la fuerza: una `default_view` fuera de `views` equivale a no haberla puesto.

### `time`: la rejilla horaria

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `time.slot_duration` | `string` | `'30m'` | Duración de una ranura de la rejilla horaria (semana, día, recursos). Es también el paso de `snap` si no se indica otro |
| `time.slot_min_time` | `string` | `'00:00'` | Hora a la que empieza la banda visible de cada día |
| `time.slot_max_time` | `string` | `'24:00'` | Hora a la que acaba. Tiene que ser posterior a `slot_min_time` (si no, lanza una excepción). Con `26:00` el eje llega a las 02:00 del día siguiente |
| `time.event_min_minutes` | `int` | `15` | Alto mínimo de un evento con hora y duración mínima a efectos de colisión |
| `time.event_short_minutes` | `int` | `30` | Por debajo de esta duración, el evento se pinta en una sola línea |
| `time.next_day_threshold` | `string` | `'00:00'` | Un evento con hora que acaba en un día a partir de esta hora (incluida) ocupa también ese día en el mes y la agenda. Acabar justo a las 00:00 nunca lo ocupa |
| `time.default_timed_duration` | `string` | `'1h'` | Duración de un evento con hora sin fin (o con un fin que no es posterior al inicio) |
| `time.default_all_day_duration` | `int` | `1` | Días que dura un evento de día completo sin fin |

### `month`

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `month.max_events` | `?int` | `null` | Eventos visibles por celda; el resto va al «+N más». `null` = sin límite. Es siempre un número: no hay modo automático por píxeles |
| `month.max_event_rows` | `?int` | `null` | Límite de filas de la celda contando la del «+N más» |
| `month.fixed_week_count` | `bool` | `true` | El mes siempre dibuja seis semanas |
| `month.show_non_current_dates` | `bool` | `true` | Dibuja los días de los meses vecinos |

### `time_grid`

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `time_grid.all_day_max_rows` | `?int` | `5` | Filas máximas del carril de todo el día; el resto va al «+N más» |
| `time_grid.slot_event_overlap` | `bool` | `true` | Los eventos solapados se pisan un poco (el siguiente asoma por detrás). Con `false` se reparten el ancho sin pisarse |
| `time_grid.max_stack` | `?int` | `null` | Máximo de eventos lado a lado; los demás van a un «+N más» |
| `time_grid.dates_above_resources` | `bool` | `false` | En las vistas por recurso, la cabecera de dos niveles pasa de recurso → fechas a fecha → recursos |

### `timeline`

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `timeline.slots` | `array<string, string>` | `timeline-day` `'1h'`, `timeline-week` `'1d'`, `timeline-month` `'1d'` | Duración de cada ranura por vista. Una vista sin entrada usa `'1h'` |
| `timeline.scroll_time` | `string` | `'08:00'` | Hora a la que se abre un timeline por horas si «ahora» no cae dentro. Los de días empiezan por el principio |
| `timeline.virtualize_after` | `int` | `100` | Con más filas que esto, `KoreCalendar` solo monta una ventana y el resto son espaciadores; pide la ventana nueva al hacer scroll. `<x-kore::calendar>` nunca virtualiza: sin Livewire no hay servidor que dé más filas, así que pinta todas, por muchas que sean |
| `timeline.window` | `int` | `60` | Filas de la ventana virtualizada de `KoreCalendar`. `0` o menos: sin tope, se montan todas |

### `recurrence`

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `recurrence.max_occurrences` | `int` | `1000` | Ocurrencias máximas por serie y vista. Pasado el tope se corta y se avisa en el log (`logger()->warning`) |

### `interaction`

Todo apagado por defecto: un calendario es de solo lectura hasta que su clase lo diga. Lo que manda el navegador se vuelve a validar siempre en el servidor con estos mismos ajustes. Solo tiene efecto en `KoreCalendar`; el componente sin estado no tiene gestos.

| Clave | Tipo | Default | Qué hace |
|---|---|---|---|
| `interaction.editable` | `bool` | `false` | Se pueden mover y redimensionar los eventos. Cada evento puede decidir lo suyo con `editable`, `startEditable` y `durationEditable` |
| `interaction.selectable` | `bool` | `false` | Se pueden seleccionar rangos de fechas arrastrando |
| `interaction.snap` | `?string` | `null` | Paso del gesto (`'15m'`). `null` = `time.slot_duration`. Si no divide el slot, se usa el slot |
| `interaction.event_overlap` | `bool` | `true` | `false`: ningún evento se puede solapar con otro (salvo los del mismo `groupId`) |
| `interaction.event_constraint` | `?string` | `null` | `'businessHours'`: un evento movido o redimensionado tiene que quedar en horario laboral. Cualquier otro valor lanza una excepción |
| `interaction.select_constraint` | `?string` | `null` | Lo mismo para seleccionar un rango |
| `interaction.select_overlap` | `bool` | `true` | `false`: no se puede seleccionar un rango que pisa un evento |
| `interaction.all_day_maintain_duration` | `bool` | `false` | Al pasar de día completo a con hora (o al revés) se conserva la duración en vez de usar la de por defecto |
| `interaction.resource_editable` | `bool` | `true` | Se puede cambiar un evento de recurso arrastrándolo |
| `interaction.event_click` | `bool` | `false` | Los eventos se pueden pulsar (`onEventClick()`). `KoreCalendar` lo activa solo con `eventForm()` o si se sobrescribe `onEventClick()` |
| `interaction.valid_range` | `array` | `['start' => null, 'end' => null]` | Límites de lo editable, `YYYY-MM-DD`. El `end` incluye ese día. Un extremo `null` queda abierto |

### `formats`

Patrones de `isoFormat` de Carbon, que se adaptan al idioma del calendario (`locale`) sin `ext-intl`: `LL` es «16 de octubre de 2026» o «October 16, 2026», `LT` la hora del idioma («14:30» o «2:30 PM»).

| Clave | Default | Dónde se usa |
|---|---|---|
| `formats.title_month` | `'MMMM YYYY'` | Título de la vista mes (se capitaliza) |
| `formats.title_day` | `'dddd, LL'` | Título de las vistas de un día |
| `formats.title_week_start` | `'D MMM'` | Inicio del título de una semana que cruza de mes: «28 sept – 4 oct 2026» |
| `formats.title_week_end` | `'D MMM YYYY'` | Fin del título de una semana. Si empieza y acaba en el mismo mes, el inicio es solo `D` |
| `formats.day_header` | `'ddd D'` | Cabecera de columna en semana, día y recursos, y ranuras de día del timeline |
| `formats.day_header_long` | `'dddd, LL'` | Fecha completa para lectores de pantalla, títulos de día de la agenda y nombre del «+N más» |
| `formats.weekday` | `'ddd'` | Cabecera de columnas de la vista mes |
| `formats.day_number` | `'D'` | Número del día en cada celda del mes |
| `formats.time` | `'LT'` | Toda hora: eventos, eje horario y ranuras del timeline |

Los formatos solo se cambian en el config o, en una clase, con `$this->set('formats', ['title_month' => 'MMM YYYY'])`; una clave suelta (`formats.title_month`) no funciona con `set()`.

## Métodos de `configure()`

`configure()` se ejecuta en cada petición (en `booted()`). Todos los métodos son `protected`, devuelven `static` y se encadenan.

```php
public function configure(): void
{
    $this->views(['week', 'month'])
        ->timezone(auth()->user()->timezone)
        ->slotMinTime('08:00')->slotMaxTime('20:00')
        ->editable()->selectable()->snap('15m')
        ->set('time.event_min_minutes', 20);
}
```

| Método | Clave que cambia | Notas |
|---|---|---|
| `views(array)` | `views` | Solo para este calendario |
| `defaultView(string)` | `default_view` | |
| `timezone(string)` | `timezone` | |
| `locale(string)` | `locale` | `'en'` o `'es'`. Cambia los textos y las fechas de este calendario |
| `firstDay(int)` | `first_day` | |
| `hiddenDays(array)` | `hidden_days` | |
| `weekNumbers('iso'\|'locale'\|false = 'iso')` | `week_numbers` | |
| `slotDuration(string)`, `slotMinTime(string)`, `slotMaxTime(string)` | `time.slot_duration`, `time.slot_min_time`, `time.slot_max_time` | |
| `maxEvents(?int)` | `month.max_events` | `null` deja el del config |
| `nowIndicator(bool = true)` | `now_indicator` | |
| `datesAboveResources(bool = true)` | `time_grid.dates_above_resources` | |
| `eventOrder(string, bool $strict = false)` | `event_order`, `event_order_strict` | |
| `businessHours(bool\|array = true)` | `business_hours` | |
| `highlightConflicts(bool = true)` | `highlight_conflicts` | |
| `editable(bool = true)` | `interaction.editable` | |
| `selectable(bool = true)` | `interaction.selectable` | |
| `snap(string)` | `interaction.snap` | |
| `eventOverlap(bool = true)` | `interaction.event_overlap` | |
| `eventConstraint(?string = 'businessHours')` | `interaction.event_constraint` | `null` quita la regla del config |
| `selectConstraint(?string = 'businessHours')` | `interaction.select_constraint` | `null` quita la regla del config |
| `selectOverlap(bool = true)` | `interaction.select_overlap` | |
| `allDayMaintainDuration(bool = true)` | `interaction.all_day_maintain_duration` | |
| `resourceEditable(bool = true)` | `interaction.resource_editable` | |
| `validRange(?string $start, ?string $end)` | `interaction.valid_range` | Un extremo `null` queda abierto; con los dos `null` quita el límite del config |
| `eventClick(bool = true)` | `interaction.event_click` | `false` lo apaga aunque haya `eventForm()` |
| `eventForm(bool = true)` | — | No es una clave del config: activa el formulario de crear y editar |
| `undoable(bool = true)` | — | No es una clave del config: ofrece «Deshacer» tras mover o redimensionar |
| `set(string $key, mixed $value)` | cualquiera | Clave con puntos: `->set('month.max_event_rows', 4)` |

Qué hace cada gesto, sus hooks y el contrato de `persistMove()`, `createEvent()` y compañía: [KoreCalendar](kore-calendar.md).

## Props de `<x-kore::calendar>`

Las props llevan el nombre de la clave del config (en kebab-case) y ganan sobre él. El componente es de solo lectura: no tiene props de interacción. Las claves del config que no están aquí (`month.max_event_rows`, `time.event_min_minutes`, `timeline.*`…) se cambian solo en el config.

| Prop | Tipo | Default | Descripción |
|---|---|---|---|
| `events` | `iterable` | `[]` | Arrays con la forma de un evento, `CalendarEvent` o fuentes (`EventSource`: `EloquentSource`, `ICalSource`…). Mezclarlos vale |
| `resources` | `iterable\|ResourceSet\|null` | `null` | Recursos. Sin ellos no se ofrecen las vistas por recurso |
| `view` | `string` | config `default_view`, o la primera de `views` | Vista inicial. `?view=` (si la barra está activa) la sustituye. Si no es una de las vistas del selector, se usa `default_view` |
| `date` | `string\|DateTimeInterface\|Marker` | hoy | Fecha que se muestra. `?date=YYYY-MM-DD` la sustituye |
| `views` | `array` | config `views` | Vistas del selector |
| `timezone` | `string` | config `timezone` | Zona de la vista |
| `locale` | `string` | config `locale`, o `app()->getLocale()` | Idioma de los textos y las fechas (`en` o `es`) |
| `first-day` | `int` | config `first_day` | Primer día de la semana |
| `hidden-days` | `array` | config `hidden_days` | Días ocultos |
| `week-numbers` | `false\|'iso'\|'locale'` | config `week_numbers` | Números de semana en el mes |
| `slot-duration` | `string` | config `time.slot_duration` | Ranura de la rejilla horaria |
| `slot-min-time` | `string` | config `time.slot_min_time` | Inicio de la banda visible |
| `slot-max-time` | `string` | config `time.slot_max_time` | Fin de la banda visible |
| `max-events` | `?int` | config `month.max_events` | Límite de eventos por celda del mes |
| `now-indicator` | `bool` | config `now_indicator` | Línea de «ahora» |
| `business-hours` | `bool\|array` | config `business_hours` | Horario laboral |
| `highlight-conflicts` | `bool` | config `highlight_conflicts` | Marcar solapes |
| `toolbar` | `bool` | `true` | Barra con navegación y selector de vista. Sin ella, `?date=` y `?view=` se ignoran |
| `date-param` | `string` | `'date'` | Nombre del parámetro de la URL para la fecha |
| `view-param` | `string` | `'view'` | Nombre del parámetro de la URL para la vista |
| `calendar` | `RenderedCalendar` | `null` | Interna: `KoreCalendar` pasa aquí su calendario ya montado. No se usa a mano |
| `mode` | `'links'\|'livewire'` | `'links'` | Interna: `links` navega con enlaces; `livewire` con las acciones del componente |

El resto de atributos (`class`, `id`, `data-*`) van al `div` que envuelve la barra y la vista.

## Idioma y traducciones

El calendario habla **inglés o español**, y los textos y las fechas siempre en el mismo: nunca sale una fecha en inglés con los botones en español. El idioma se resuelve en este orden:

1. El del calendario: `->locale('en')` en `configure()` o la prop `locale` de `<x-kore::calendar>`.
2. `kore-calendar.locale` del config.
3. El de la aplicación (`app()->getLocale()`).

De lo que salga solo cuenta el idioma base (`es_MX`, `es-AR` → `es`; `en_GB` → `en`). Si no es `en` ni `es`, se usa `kore-calendar.fallback_locale` (`'en'` por defecto): una aplicación en francés ve el calendario en inglés, con las fechas en inglés. La primera semana del año y el orden alfabético sí usan el idioma pedido tal cual (`en_GB` numera las semanas como ISO).

Los textos viven en `lang/en/calendar.php` y `lang/es/calendar.php` del paquete (namespace `kore-calendar`). Ninguna vista lleva un texto escrito a mano. Tres formas de cambiarlos, de menos a más fuerte:

- **Editar los de serie**: `php artisan vendor:publish --tag=kore-calendar-lang` copia `lang/` a `lang/vendor/kore-calendar/`. Laravel lee primero esa copia, y basta con dejar las claves que cambias.
- **`translations` del config**: una lista de *overrides*, vacía por defecto. Una clave puesta aquí gana a la del archivo de idioma.
- **Por idioma**: dentro de `translations`, una clave cuyo valor es un array (`'en'`, `'es'`) solo vale en ese idioma y gana a la general.

```php
// config/kore-calendar.php
'translations' => [
    'today' => 'Ahora',                  // en los dos idiomas
    'en' => ['today' => 'Right now'],    // solo en inglés; gana a la anterior
],
```

El idioma del calendario viaja con el formulario de evento y el diálogo de alcance (son overlays aparte), con los textos de teclado que recibe el JavaScript y con el formateo de fechas de los anuncios, así que todo sale en el mismo idioma. Una clave que no existe en ningún sitio, por ejemplo la de una vista que añadas (`view_*`), se muestra con su nombre.

> **Componentes de kore-ui.** El formulario usa los selectores de fecha, hora y lista de kore-ui, y los avisos son toasts suyos. Sus textos fijos («Elegir fecha», «Mes anterior», «Limpiar», «Cerrar notificación»…) salen de `kore-ui.form.translations.*` y `kore-ui.ui.translations.*`, que el calendario no controla: en una aplicación en inglés, tradúcelos en la configuración de kore-ui. Los nombres de los meses y los días del selector de fecha sí siguen al idioma del calendario.

Los marcadores `:nombre` se sustituyen por texto plano al mostrarse. En las tablas, la columna **Marcadores** dice cuáles lleva cada texto; las claves sin marcadores no los admiten. Las tablas muestran el texto español; el inglés está en `lang/en/calendar.php`.

### Vista y barra

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `all_day` | | Carril de todo el día, agenda e interruptor del formulario |
| `more` | `:count` | Botón «+N más» |
| `more_label` | `:count`, `:date` | Nombre accesible de ese botón |
| `events_of` | `:date` | Nombre accesible de su ventana |
| `close` | | Cerrar esa ventana |
| `week` | | Cabecera de la columna de números de semana |
| `week_number` | `:number` | Texto para lectores de pantalla de cada fila de semana |
| `no_events` | | Agenda sin eventos |
| `previous`, `next`, `today` | | Botones de la barra |
| `views` | | Nombre accesible del grupo de botones de vista |
| `view_month`, `view_week`, `view_day`, `view_list`, `view_resource-week`, `view_resource-day`, `view_timeline-day`, `view_timeline-week`, `view_timeline-month` | | Botón de cada vista. Si añades una vista, falta su `view_*` |
| `resources` | | Cabecera de la columna de recursos del timeline |
| `toggle_group` | `:group` | Nombre accesible del botón que pliega un grupo del timeline |

### Teclado

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `kbd_help` | | Instrucciones asociadas (`aria-describedby`) a cada evento que se puede mover. El texto lleva un `id` propio de cada calendario |
| `kbd_grabbed` | | Anuncio al agarrar un evento con Espacio |
| `kbd_cancelled` | | Anuncio al cancelar |

### Rechazos al mover, redimensionar o seleccionar

Salen como motivo en un toast de error y como anuncio. `move_rejected` y `resize_rejected` son el título del toast; los demás, el motivo.

| Clave | Marcadores |
|---|---|
| `move_rejected`, `resize_rejected` | |
| `not_editable`, `resource_locked`, `out_of_range`, `outside_business_hours` | |
| `overlap_rejected` | `:title` (el evento con el que choca) |
| `outside_business_hours_resource` | `:resource` (título del recurso) |
| `event_not_found`, `resource_not_found`, `invalid_duration`, `invalid_change`, `persist_failed` | |
| `hook_missing` | `:hook` (`persistMove`, `createEvent`…), `:class` | Motivo del rechazo cuando el calendario es editable y no implementa ese hook |

### Anuncios y conflictos

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `event_moved` | `:title`, `:date` | Anuncio `aria-live` al mover (««Reunión» movido al …») |
| `event_resized` | `:title`, `:date` | Al cambiar el fin. También se anuncia así cada pulsación de Mayúsculas + flecha al redimensionar con el teclado |
| `event_resized_start` | `:title`, `:date` | Al cambiar el inicio |
| `conflict` | `:with` | Texto para lectores de pantalla de un evento en conflicto: los títulos de los otros, entre las comillas del idioma (`event_quoted`) y separados por comas |
| `event_quoted` | `:title` | Un título entre comillas dentro de una lista: «Reunión» en español, “Reunión” en inglés |

### Deshacer

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `undo` | | Acción del toast |
| `undo_done`, `undo_failed` | | Resultado |

### Formulario de evento

Los fallos salen como motivo en un toast de error; los éxitos, en uno de éxito.

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `create_rejected`, `update_rejected`, `delete_rejected` | | Título del toast de error |
| `event_created`, `event_updated`, `event_deleted` | `:title` | Toast de éxito |
| `form_create`, `form_edit` | | Título del formulario |
| `form_title`, `form_start_date`, `form_start_time`, `form_end_date`, `form_end_time`, `form_resource`, `form_color` | | Etiquetas de los campos |
| `form_pick_date`, `form_pick_time`, `form_no_resource`, `form_no_color` | | Placeholders |
| `form_save`, `form_cancel`, `form_delete` | | Botones (`form_cancel` es también el del diálogo de alcance) |
| `form_delete_confirm` | `:title` | Pregunta de la confirmación al eliminar |
| `form_delete_description` | | Texto de esa confirmación |
| `form_required`, `form_invalid`, `form_end_before_start` | | Errores de validación |
| `color_primary`, `color_success`, `color_warning`, `color_destructive`, `color_info`, `color_chart_1` … `color_chart_8` | | Nombre de cada color del selector |

### Series recurrentes

| Clave | Marcadores | Dónde sale |
|---|---|---|
| `scope_move`, `scope_resize`, `scope_edit`, `scope_delete` | | Título del diálogo de alcance, según la acción |
| `scope_question` | `:title` | Pregunta del diálogo |
| `scope_this`, `scope_following`, `scope_all` | | Botones de alcance |

## Ver también

- [Calendar](getting-started.md): eventos, recursos, recurrencia y horario laboral.
- [KoreCalendar](kore-calendar.md): acciones, hooks y formulario.
- [Theming](theming.md): tokens CSS y dark mode.
