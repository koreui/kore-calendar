<?php

/*
 * Configuración de kore-ui/calendar.
 *
 * Archivo propio y no una sección de `kore-ui.php`: un segundo paquete no debe
 * depender de que la aplicación haya publicado la configuración de otro.
 *
 * Todo lo de aquí se puede cambiar también por calendario, con la prop de la
 * etiqueta o con `configure()` en la clase base. Lo lee `CalendarSettings`.
 */

return [

    /* Vista inicial: month | week | day | list | resource-week | resource-day | timeline-day | timeline-week | timeline-month */
    'default_view' => 'month',

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
        'virtualize_after' => 100,        // con más filas, solo se monta una ventana
        'window' => 60,                   // filas por ventana
    ],

    'event_order' => 'start,-duration,allDay,title',
    'event_order_strict' => false,
    'now_indicator' => true,

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

    /* Textos de interfaz. Ninguna vista lleva texto fijo (lo vigila tests/Ui/TextosEnInglesTest.php). */
    'translations' => [
        'all_day' => 'Todo el día',
        'more' => '+:count más',
        'more_label' => 'Ver :count eventos más del :date',
        'week' => 'Sem.',
        'week_number' => 'Semana :number',
        'no_events' => 'No hay eventos en este periodo',
        'close' => 'Cerrar',
        'events_of' => 'Eventos del :date',
        'previous' => 'Anterior',
        'next' => 'Siguiente',
        'today' => 'Hoy',
        'views' => 'Vista',
        'view_month' => 'Mes',
        'view_week' => 'Semana',
        'view_day' => 'Día',
        'view_list' => 'Agenda',
        'view_resource-week' => 'Recursos · semana',
        'view_resource-day' => 'Recursos · día',
        'view_timeline-day' => 'Timeline · día',
        'view_timeline-week' => 'Timeline · semana',
        'view_timeline-month' => 'Timeline · mes',
        'resources' => 'Recursos',
        'toggle_group' => 'Mostrar u ocultar :group',
    ],

];
