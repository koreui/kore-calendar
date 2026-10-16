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
    'view_timeline-day' => 'Timeline · día',
    'view_timeline-week' => 'Timeline · semana',
    'view_timeline-month' => 'Timeline · mes',
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
    'persist_failed' => 'No se pudo guardar el cambio',
    'event_moved' => '«:title» movido al :date',
    'event_resized' => '«:title» ahora termina :date',
    'event_resized_start' => '«:title» ahora empieza :date',

    // Lo que ve quien aún no ha implementado un hook de `KoreCalendar` (persistMove,
    // persistResize, createEvent, updateEvent, deleteEvent): sale como motivo del rechazo.
    'hook_missing' => 'Este calendario todavía no guarda ese cambio: implementa :hook() en :class.',

    // `->highlightConflicts()`: el texto para lectores de pantalla de un evento que se solapa con otros.
    'conflict' => 'En conflicto con :with',

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
];
