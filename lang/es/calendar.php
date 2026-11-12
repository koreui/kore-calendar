<?php

/*
 * Textos de interfaz de kore-ui/calendar (español).
 *
 * Ninguna vista lleva texto fijo (lo vigila tests/Ui/TextosEnInglesTest.php) y cada
 * clave de aquí existe también en lang/en/calendar.php, con los mismos marcadores
 * (:count, :date…): lo comprueba tests/Ui/IdiomasTest.php.
 *
 * Para cambiar uno sin tocar el paquete: `php artisan vendor:publish --tag=kore-calendar-lang`
 * y edita la copia en lang/vendor/kore-calendar/, o ponlo en `translations` del config.
 */

return [
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
    'view_timeline-day' => 'Cronograma · día',
    'view_timeline-week' => 'Cronograma · semana',
    'view_timeline-month' => 'Cronograma · mes',
    'resources' => 'Recursos',
    'kbd_help' => 'Espacio para mover con el teclado: flechas para cambiar de día u hora, Mayúsculas y flechas para cambiar la duración, Intro para soltar y Escape para cancelar.',
    'kbd_grabbed' => 'Agarrado.',
    'kbd_cancelled' => 'Cancelado.',
    'toggle_group' => 'Mostrar u ocultar :group',

    // El título de un evento entre comillas, en los textos que lo nombran dentro de una lista.
    'event_quoted' => '«:title»',

    // Interacción: los motivos de rechazo salen en un toast; los éxitos de mover y
    // redimensionar solo se anuncian por aria-live (con `undoable()` también en un toast).
    'move_rejected' => 'No se pudo mover el evento',
    'resize_rejected' => 'No se pudo cambiar la duración del evento',
    'not_editable' => 'Este evento no se puede editar',
    'resource_locked' => 'Este evento no se puede cambiar de recurso',
    'overlap_rejected' => 'Se solapa con «:title»',
    'out_of_range' => 'Fuera del rango permitido',
    'outside_business_hours' => 'Fuera del horario laboral',
    'outside_business_hours_resource' => 'Fuera del horario de :resource',
    'event_not_found' => 'El evento ya no está disponible',
    'resource_not_found' => 'El recurso de destino no existe',
    'invalid_duration' => 'La duración no puede ser cero ni negativa',
    'invalid_change' => 'El cambio no es válido',
    'overlap_rejected_hidden' => 'Se solapa con otro evento',
    'persist_failed' => 'No se pudo guardar el cambio',
    'event_moved' => '«:title» movido al :date',
    'event_resized' => '«:title» ahora termina :date',
    'event_resized_start' => '«:title» ahora empieza :date',

    // Lo que ve quien aún no ha implementado un hook de `KoreCalendar` (persistMove,
    // persistResize, createEvent, updateEvent, deleteEvent): sale como motivo del rechazo.
    'hook_missing' => 'Este calendario todavía no guarda ese cambio: implementa :hook() en :class.',

    // `->highlightConflicts()`: el texto para lectores de pantalla de un evento que se solapa con otros.
    'conflict' => 'En conflicto con :with',
    // Con más de tres conflictos solo se nombran tres títulos; el resto se cuenta (:titles es la lista ya entrecomillada).
    'conflict_more' => ':titles y :count más',

    // Deshacer (`->undoable()`): un toast con la acción tras mover o redimensionar.
    'undo' => 'Deshacer',
    'undo_done' => 'Cambio deshecho',
    'undo_failed' => 'No se pudo deshacer el cambio',

    // Crear, editar y eliminar con el formulario (`->eventForm()`). Los fallos
    // salen en un toast de error con el motivo; los éxitos, en uno de éxito.
    'create_rejected' => 'No se pudo crear el evento',
    'update_rejected' => 'No se pudo guardar el evento',
    'delete_rejected' => 'No se pudo eliminar el evento',
    'event_created' => '«:title» creado',
    'event_updated' => '«:title» guardado',
    'event_deleted' => '«:title» eliminado',

    // El formulario de evento. «Todo el día» es `all_day`, la de arriba.
    'form_create' => 'Nuevo evento',
    'form_edit' => 'Editar evento',
    'form_title' => 'Título',
    'form_start_date' => 'Fecha de inicio',
    'form_start_time' => 'Hora de inicio',
    'form_end_date' => 'Fecha de fin',
    'form_end_time' => 'Hora de fin',
    'form_resource' => 'Recurso',
    'form_color' => 'Color',
    'color_custom' => 'Personalizado',
    'form_pick_date' => 'Elige una fecha',
    'form_pick_time' => 'Elige una hora',
    'form_no_resource' => 'Sin recurso',
    'form_no_color' => 'Sin color',
    'form_save' => 'Guardar',
    'form_cancel' => 'Cancelar',
    'form_delete' => 'Eliminar',
    'form_delete_confirm' => '¿Eliminar «:title»?',
    'form_delete_description' => 'Esta acción no se puede deshacer.',
    'form_required' => 'Este campo es obligatorio',
    'form_invalid' => 'El valor no es válido',
    'form_end_before_start' => 'El fin no puede ser anterior al inicio',

    // Series recurrentes: el diálogo que pregunta a qué alcanza el cambio de una
    // ocurrencia. «Cancelar» es `form_cancel`.
    'scope_move' => 'Mover un evento recurrente',
    'scope_resize' => 'Cambiar la duración de un evento recurrente',
    'scope_edit' => 'Editar un evento recurrente',
    'scope_delete' => 'Eliminar un evento recurrente',
    'scope_question' => '«:title» se repite. ¿Qué eventos de la serie quieres cambiar?',
    'scope_this' => 'Solo este evento',
    'scope_following' => 'Este y los siguientes',
    'scope_all' => 'Todos los eventos',

    // Los colores del selector (tokens de kore-ui).
    'color_primary' => 'Primario',
    'color_success' => 'Éxito',
    'color_warning' => 'Aviso',
    'color_destructive' => 'Peligro',
    'color_info' => 'Información',
    'color_chart_1' => 'Paleta 1',
    'color_chart_2' => 'Paleta 2',
    'color_chart_3' => 'Paleta 3',
    'color_chart_4' => 'Paleta 4',
    'color_chart_5' => 'Paleta 5',
    'color_chart_6' => 'Paleta 6',
    'color_chart_7' => 'Paleta 7',
    'color_chart_8' => 'Paleta 8',

    // Importar un archivo iCalendar (`ICalReader`): los errores y avisos que se le enseñan a quien
    // lo sube. Los nombres de iCalendar (VEVENT, DTSTART, RRULE, TZID…) no se traducen: son los
    // que va a buscar en su archivo. `:line` es la línea del archivo.
    'ical_line_invalid' => 'Línea :line: no es una propiedad iCalendar válida (se espera :format): «:preview».',
    'ical_param_unclosed_quote' => 'Línea :line: el parámetro :param abre comillas y no las cierra.',
    'ical_missing_colon' => 'Línea :line: la propiedad :name no tiene «:» antes del valor.',
    'ical_too_big' => 'El archivo pesa :size y el máximo que se lee es :max (opción maxBytes de ICalReader, o `ical.max_bytes` en el config).',
    'ical_not_icalendar' => 'El contenido no es un archivo iCalendar: no se encontró BEGIN:VCALENDAR.',
    'ical_version' => 'Línea :line: VERSION «:version» no es 2.0; se lee como iCalendar 2.0.',
    'ical_uid_repeated' => 'Línea :line: el UID «:uid» está repetido; los dos eventos se conservan.',
    'ical_end_without_begin' => 'Línea :line: END::name sin su BEGIN; se ignora.',
    'ical_unclosed' => 'Línea :line: el :name no se cierra (falta END::name).',
    'ical_unclosed_truncated' => 'Línea :line: el :name no se cierra (falta END::name); el archivo parece truncado.',
    'ical_too_many_lines' => 'Línea :line: el archivo tiene demasiadas líneas para su tamaño máximo; se dejó de leer aquí.',
    'ical_too_many_events' => 'Línea :line: el archivo tiene más de :max VEVENT (opción maxEvents); se dejó de leer aquí.',
    'ical_too_deep' => 'Línea :line: los componentes están anidados a más de :max niveles; se dejó de leer aquí.',
    'ical_not_utf8' => 'El archivo no está en UTF-8; se interpretó como Windows-1252.',
    'ical_no_dtstart' => 'Línea :line: el VEVENT no tiene DTSTART.',
    'ical_many_rrule' => 'Línea :line: el VEVENT tiene varios RRULE; solo se usa el primero.',
    'ical_unrepresentable' => 'Línea :line: :name no se puede representar y se ignora; la serie puede mostrar ocurrencias de más o de menos.',
    'ical_too_many_exdate' => 'Línea :line: el VEVENT tiene más de :max EXDATE; se ignoran los demás.',
    'ical_range_obsolete' => 'Línea :line: RECURRENCE-ID;RANGE=:range está obsoleto y no se soporta; se trata como una sola ocurrencia.',
    'ical_override_with_rrule' => 'Línea :line: una ocurrencia modificada no puede llevar RRULE; se ignora.',
    'ical_exdate_without_rrule' => 'Línea :line: EXDATE sin RRULE no excluye nada; se ignora.',
    'ical_no_uid_strict' => 'Línea :line: el VEVENT no tiene UID (RFC 5545 lo exige).',
    'ical_no_uid' => 'Línea :line: el VEVENT no tiene UID; se le dio el id «:uid».',
    'ical_url_discarded' => 'Línea :line: la URL «:url» no es relativa ni http, https, mailto o tel; se descarta.',
    'ical_event_error' => 'Línea :line: evento «:id»: :reason',
    'ical_too_many_attendees' => 'Línea :line: el VEVENT tiene más de :max ATTENDEE; se ignoran los demás.',
    'ical_date_invalid' => 'Línea :line: :name «:value» no es una fecha iCalendar válida (se espera 20261016, 20261016T090000 o 20261016T090000Z).',
    'ical_date_nonexistent' => 'Línea :line: :name «:value» no es una fecha que exista.',
    'ical_duration_invalid' => 'Línea :line: DURATION «:value» no es una duración válida (se espera P1D, PT1H30M, P1W…).',
    'ical_rrule_invalid' => 'Línea :line: RRULE «:rule» no válida (:reason).',
    'ical_tz_no_tzid' => 'Línea :line: el VTIMEZONE no tiene TZID.',
    'ical_tz_incomplete' => 'Línea :line: el :name del VTIMEZONE «:tzid» necesita DTSTART, TZOFFSETFROM y TZOFFSETTO.',
    'ical_tz_rrule_not_yearly' => 'Línea :line: el :name del VTIMEZONE «:tzid» tiene una RRULE que no es FREQ=YEARLY; un VTIMEZONE cambia de hora una vez al año como mucho, así que se ignora su definición.',
    'ical_tz_too_many_rdate' => 'Línea :line: el VTIMEZONE «:tzid» tiene más de :max fechas RDATE; se ignora su definición.',
    'ical_tz_empty' => 'Línea :line: el VTIMEZONE «:tzid» no define ningún STANDARD ni DAYLIGHT.',
    'ical_tz_offset_invalid' => 'Línea :line: :name «:value» no es un desplazamiento válido (se espera +0200 o -0500).',
    'ical_tz_datetime_invalid' => 'Línea :line: :name «:value» no es una fecha y hora válida (se espera 20261025T030000).',
    'ical_tzid_unknown' => 'Línea :line: el TZID «:tzid» no es una zona horaria conocida y el archivo no trae su VTIMEZONE, así que no se sabe a qué hora corresponde.',
    'ical_tzid_fixed_offset' => 'Línea :line: el TZID «:tzid» no es una zona IANA; su VTIMEZONE no tiene cambios de hora y se usó el desplazamiento fijo :offset.',
    'ical_tzid_inferred' => 'Línea :line: el TZID «:tzid» no es una zona IANA; se usó la definición de su VTIMEZONE, que coincide con «:zone».',
    'ical_tzid_unmatched' => 'Línea :line: el TZID «:tzid» no es una zona IANA y ninguna coincide con su VTIMEZONE; se usa el desplazamiento :offset en la fecha de cada evento (una serie no seguirá los cambios de hora).',
];
