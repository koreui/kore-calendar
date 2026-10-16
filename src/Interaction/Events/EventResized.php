<?php

namespace KoreUi\Calendar\Interaction\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;

/** Lo mismo que `EventMoved`, para un cambio de duración. */
final class EventResized
{
    use Dispatchable;

    public function __construct(
        public string $calendarClass,
        public CalendarEvent $event,
        public EventMutation $mutation,
    ) {}
}
