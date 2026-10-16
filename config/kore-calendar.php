<?php

/*
 * Configuración de kore-ui/calendar.
 *
 * Archivo propio y no una sección de `kore-ui.php`: un segundo paquete no debe
 * depender de que la aplicación haya publicado la configuración de otro.
 *
 * Todo lo de aquí se puede cambiar también por calendario, con la prop de la
 * etiqueta o con `configure()` en la clase base. Lo lee `CalendarSettings`.
 *
 * Al publicarlo basta con dejar las claves que cambias: el paquete funde lo tuyo con
 * estos valores a cualquier profundidad (un `formats` con un solo formato conserva los
 * demás). Las listas (`views`, `hidden_days`, los tramos de `business_hours`) no se
 * funden: la tuya sustituye a la de aquí entera.
 */

return [

    /*
    | Idioma de los textos y de las fechas: 'en' o 'es'. null = el de la aplicación
    | (`app()->getLocale()`). Una variante (`es_MX`, `en_GB`) cuenta como su idioma base.
    | Los textos salen de `lang/{idioma}/calendar.php` y las fechas del mismo idioma
    | (Carbon `isoFormat`), así que nunca se mezclan. También se puede fijar por
    | calendario: `->locale('en')` en `configure()` o la prop `locale`.
    */
    'locale' => null,

    /* El idioma que se usa cuando el pedido no es 'en' ni 'es' (por ejemplo, una aplicación en francés). */
    'fallback_locale' => 'en',

    /*
    | Vista inicial: month | week | day | list | resource-week | resource-day | timeline-day | timeline-week | timeline-month
    | Cuando la URL (?view=) o la etiqueta (view="…") no piden otra. Si no está en `views`
    | (o es de recursos y no hay recursos), se abre la primera de `views`.
    */
    'default_view' => 'month',

    /*
    | Vista para pantallas estrechas (max-width: 640px), p. ej. 'day' o 'list'. null = ninguna.
    | Solo se usa en la PRIMERA carga sin ?view= en la URL: la elección explícita del usuario
    | (un enlace con ?view=, o el selector) manda siempre. Si no está en `views` (o es de recursos
    | y no hay recursos), se ignora. El servidor no sabe el ancho de la pantalla: lo hace el cliente
    | al iniciar, con un render extra la primera vez. También: ->mobileView('day') en `configure()`
    | y la prop mobile-view.
    */
    'mobile_view' => null,

    /* Vistas del selector. Las de recursos solo se ofrecen si el calendario tiene recursos. */
    'views' => ['month', 'week', 'day', 'list', 'resource-week', 'resource-day', 'timeline-day', 'timeline-week', 'timeline-month'],

    /* Zona horaria de la vista. null = config('app.timezone'). */
    'timezone' => null,

    /*
    | Primer día de la semana (0 = domingo … 6 = sábado). null = el mismo que el
    | datepicker de kore-ui, para que la rejilla y el selector de fecha coincidan.
    */
    'first_day' => null,

    /* Numeración de semanas: false (no se muestran) | 'iso' | 'locale'. */
    'week_numbers' => false,

    /* Días de la semana ocultos (0 = domingo). [0, 6] = solo laborables. */
    'hidden_days' => [],

    'time' => [
        'slot_duration' => '30m',
        'slot_min_time' => '00:00',
        'slot_max_time' => '24:00',       // admite más de 24 h: '26:00'
        'event_min_minutes' => 15,        // alto mínimo y mínimo para colisionar
        'event_short_minutes' => 30,      // por debajo, una sola línea
        'next_day_threshold' => '00:00',  // desde qué hora un evento ocupa también el día de su fin
        'default_timed_duration' => '1h', // si un evento con hora no trae fin
        'default_all_day_duration' => 1,  // días, si un evento de día completo no trae fin
    ],

    'month' => [
        'max_events' => null,             // límite por celda; el resto va al "+N más"
        'max_event_rows' => null,         // límite de filas contando la del "+N más"
        'fixed_week_count' => true,       // siempre 6 semanas
        'show_non_current_dates' => true, // días de los meses vecinos
    ],

    'time_grid' => [
        'all_day_max_rows' => 5,
        'slot_event_overlap' => true,     // los solapados se pisan un poco, como en FullCalendar
        'max_stack' => null,              // máximo de eventos lado a lado
        'dates_above_resources' => false, // cabecera: fecha → recursos (true) o recurso → fechas
    ],

    'timeline' => [
        'slots' => [                      // duración de cada ranura por vista
            'timeline-day' => '1h',
            'timeline-week' => '1d',
            'timeline-month' => '1d',
        ],
        'scroll_time' => '08:00',         // hora a la que se abre un timeline por horas (si no es hoy)
        'virtualize_after' => 100,        // con más filas, KoreCalendar monta solo una ventana (<x-kore::calendar> pinta todas: sin Livewire no hay quien dé más)
        'window' => 60,                   // filas por ventana
    ],

    /*
    | Topes de las series recurrentes. Una regla hostil (`FREQ=MINUTELY` desde 2020, o
    | `FREQ=SECONDLY;COUNT=99999999`) no puede tumbar la página, y limitar solo lo que se
    | dibuja no basta: php-rrule recorre y guarda en memoria TODAS las ocurrencias desde
    | `DTSTART` hasta la vista (unos 350 bytes cada una). Por eso hay dos pares de topes: lo
    | que se dibuja y lo que se recorre, cada uno por serie y sumado en todo el render.
    |
    | Pasado un tope se corta lo que falta y se avisa en el log (`logger()->warning`). Las
    | reglas de segundos a semanas sin `COUNT` ni `BYSETPOS` no recorren el pasado (el inicio se
    | adelanta); lo que no se puede adelantar (`COUNT` grande, `MONTHLY`, `YEARLY`) sí, y lo
    | acota `max_iterations`.
    */
    'recurrence' => [
        'max_occurrences' => 1000,        // por serie y vista: ocurrencias que se dibujan (las del rango más un día de margen a cada lado)
        'max_total_occurrences' => 5000,  // lo mismo, sumadas todas las series de un render
        'max_iterations' => 50000,        // por serie: ocurrencias que se recorren desde DTSTART (las anteriores a la vista también)
        'max_total_iterations' => 250000, // lo mismo, sumadas todas las series de un render
    ],

    /*
    | Lectura de archivos iCalendar (`ICalReader`, `ICalSource`): un `.ics` lo escribe un
    | tercero. Se pueden cambiar por lectura con las opciones del mismo nombre en camelCase
    | (`maxBytes`, `maxEvents`, `maxErrors`).
    */
    'ical' => [
        'max_bytes' => 5242880,           // 5 MB: un archivo más grande se rechaza con una ICalException
        'max_events' => 10000,            // VEVENT que se leen; a partir del siguiente se deja de leer y queda un error
        'max_errors' => 100,              // errores y avisos que se guardan (errors(), warnings()); los demás solo se cuentan
    ],

    'event_order' => 'start,-duration,allDay,title',
    'event_order_strict' => false,
    'now_indicator' => true,

    /*
    | Horario laboral (docs/calendar.md §7.5): las horas que NO lo son se pintan con
    | `--kore-calendar-non-business`. false = sin horario (no se pinta nada y todo
    | vale); true = lunes a viernes de 09:00 a 17:00; un tramo o una lista de tramos
    | `['daysOfWeek' => [1, 2, 3], 'startTime' => '08:00', 'endTime' => '14:00']`.
    | `endTime` admite más de 24:00 ('26:00': un turno de noche). El `businessHours` de
    | un recurso gana al de aquí para sus columnas o filas.
    */
    'business_hours' => false,

    /* Marcar con un icono y un texto para lectores de pantalla los eventos que se solapan en el mismo recurso. */
    'highlight_conflicts' => false,

    /*
    | Interacción (arrastrar, redimensionar, seleccionar). Todo apagado por
    | defecto: un calendario es de solo lectura hasta que su clase lo diga con
    | `->editable()`. Lo que manda el navegador se vuelve a validar siempre en el
    | servidor con estos mismos ajustes (docs/calendar.md §11).
    */
    'interaction' => [
        'editable' => false,                  // se pueden mover y redimensionar los eventos
        'selectable' => false,                // se pueden seleccionar rangos de fechas
        'snap' => null,                       // paso del gesto ('15m'). null = time.slot_duration; si no divide el slot, se usa el slot
        'event_overlap' => true,              // false: ningún evento se puede solapar con otro
        'event_constraint' => null,           // 'businessHours': un evento movido o redimensionado tiene que quedar en horario laboral (null = sin regla)
        'select_constraint' => null,          // 'businessHours': lo mismo para seleccionar un rango
        'select_overlap' => true,             // false: no se puede seleccionar un rango que pisa un evento
        'all_day_maintain_duration' => false, // al pasar de día completo a con hora (o al revés), conservar la duración
        'resource_editable' => true,          // se puede cambiar un evento de recurso
        'event_click' => false,               // los eventos se pueden pulsar (onEventClick). KoreCalendar lo activa solo con eventForm() o si se sobrescribe onEventClick()
        'valid_range' => [                    // límites de lo editable, 'YYYY-MM-DD' (el fin incluye ese día)
            'start' => null,
            'end' => null,
        ],
    ],

    /*
    | Formatos de Carbon `isoFormat`, que se adaptan al idioma sin ext-intl:
    | LL = «16 de octubre de 2026» / «October 16, 2026», LT = hora del locale.
    */
    'formats' => [
        'title_month' => 'MMMM YYYY',
        'title_day' => 'dddd, LL',
        'title_week_start' => 'D MMM',
        'title_week_end' => 'D MMM YYYY',
        'day_header' => 'ddd D',
        'day_header_long' => 'dddd, LL',
        'weekday' => 'ddd',
        'day_number' => 'D',
        'time' => 'LT',
    ],

    /*
    | Textos de interfaz que cambias sin tocar el paquete. Los de serie están en
    | `lang/{es,en}/calendar.php` (publicables con `--tag=kore-calendar-lang`); una clave
    | puesta aquí gana a la del archivo de idioma. Ninguna vista lleva texto fijo (lo
    | vigila tests/Ui/TextosEnInglesTest.php).
    |
    |     'translations' => [
    |         'today' => 'Ahora',                      // en todos los idiomas
    |         'en' => ['today' => 'Right now'],        // solo en inglés; gana a la anterior
    |     ],
    */
    'translations' => [],

];
