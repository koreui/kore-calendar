<?php

namespace KoreUi\Calendar\Interaction\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;

/**
 * Se dispara DESPUÉS de que `persistMove()` aceptó y la transacción se cerró,
 * para que un oyente (auditoría, notificaciones) vea ya los datos guardados.
 * `calendarClass` dice qué calendario lo originó: la misma convención que
 * `RowUpdated` en el DataTable.
 */
final class EventMoved
{
    use Dispatchable;

    public function __construct(
        public string $calendarClass,
        public CalendarEvent $event,
        public EventMutation $mutation,
    ) {}
}
