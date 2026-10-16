<?php

namespace KoreUi\Calendar\Interaction;

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * La cascada de editabilidad: evento → calendario (docs/calendar.md §6.1).
 *
 * `null` en el evento significa «que decida el calendario», no `false`, así
 * que se usa `??` y no `?:`. Es el único sitio donde vive la regla: las vistas
 * la usan para decidir qué atributos de arrastre pintar y el validador para
 * decidir si acepta lo que llega. Si las dos leyeran la cascada por su cuenta
 * acabarían discrepando, y el cliente ofrecería gestos que el servidor rechaza.
 */
final class Editability
{
    /** Mover el inicio (arrastrar). `startEditable` gana a `editable`. */
    public static function canMove(CalendarEvent $event, CalendarSettings $settings): bool
    {
        return $event->startEditable ?? $event->editable ?? $settings->editable;
    }

    /** Cambiar la duración (redimensionar). */
    public static function canResize(CalendarEvent $event, CalendarSettings $settings): bool
    {
        return $event->durationEditable ?? $event->editable ?? $settings->editable;
    }

    /** Cambiar de recurso: es mover, y además el calendario tiene que permitirlo. */
    public static function canChangeResource(CalendarEvent $event, CalendarSettings $settings): bool
    {
        return self::canMove($event, $settings) && $settings->resourceEditable;
    }
}
