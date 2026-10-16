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
        'all_day_maintain_duration' => false, // al pasar de día completo a con hora (o al revés), conservar la duración
        'resource_editable' => true,          // se puede cambiar un evento de recurso
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
        'kbd_help' => 'Espacio para mover con el teclado: flechas para cambiar de día u hora, Mayúsculas y flechas para cambiar la duración, Intro para soltar y Escape para cancelar.',
        'kbd_grabbed' => 'Agarrado.',
        'kbd_cancelled' => 'Cancelado.',
        'toggle_group' => 'Mostrar u ocultar :group',

        // Interacción: los motivos de rechazo salen en un toast; los éxitos solo se
        // anuncian por aria-live, nunca en un toast.
        'move_rejected' => 'No se pudo mover el evento',
        'resize_rejected' => 'No se pudo cambiar la duración del evento',
        'not_editable' => 'Este evento no se puede editar',
        'resource_locked' => 'Este evento no se puede cambiar de recurso',
        'overlap_rejected' => 'Se solapa con «:title»',
        'out_of_range' => 'Fuera del rango permitido',
        'event_not_found' => 'El evento ya no está disponible',
        'resource_not_found' => 'El recurso de destino no existe',
        'invalid_duration' => 'La duración no puede ser cero ni negativa',
        'invalid_change' => 'El cambio no es válido',
        'persist_failed' => 'No se pudo guardar el cambio',
        'event_moved' => '«:title» movido al :date',
        'event_resized' => '«:title» ahora termina :date',
        'event_resized_start' => '«:title» ahora empieza :date',
    ],

];
