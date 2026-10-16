<?php

namespace KoreUi\Calendar\Events;

use Carbon\CarbonImmutable;

/**
 * De dónde salen los eventos. Una fuente no conoce la rejilla ni la zona de la
 * vista: recibe un rango de instantes reales y devuelve lo que le parezca
 * relevante.
 */
interface EventSource
{
    /**
     * Eventos que intersecan [from, to), con los recurrentes ya expandidos.
     *
     * Puede devolver de más: `EventCollector` vuelve a filtrar por el rango de
     * la rejilla, que es donde se sabe cuánto dura de verdad un evento sin fin.
     * Lo que NO puede es devolver de menos, o el evento desaparece de la vista.
     *
     * @return iterable<CalendarEvent|array<string, mixed>> Arrays: pasan por `CalendarEvent::fromArray()`.
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
