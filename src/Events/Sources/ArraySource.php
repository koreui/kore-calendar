<?php

namespace KoreUi\Calendar\Events\Sources;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventSource;

/**
 * Eventos fijos, ya en memoria.
 *
 * Devuelve todos sin mirar el rango: filtrar bien exige normalizar (zona, fin
 * por defecto), y eso es del colector, que sí tiene el reloj.
 */
final class ArraySource implements EventSource
{
    /** @var list<CalendarEvent|array<string, mixed>> */
    private readonly array $events;

    /**
     * @param  iterable<CalendarEvent|array<string, mixed>>  $events
     */
    public function __construct(iterable $events)
    {
        // Un generador solo se puede recorrer una vez, y una fuente se consulta en
        // cada render. Se materializa aquí para que "fijo" sea verdad.
        $this->events = iterator_to_array($events, false);
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return $this->events;
    }
}
