<?php

namespace KoreUi\Calendar\Events;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * Un evento ya colocado en la rejilla: lo que el layout recibe.
 *
 * `range` es la hora de pared en la zona de la vista y es lo único que usa la
 * rejilla. Siempre cumple `range->end > range->start`: un evento de duración
 * cero no se puede pintar ni arrastrar, así que el normalizador nunca lo emite.
 *
 * `exactStart`/`exactEnd` guardan el instante real cuando el evento llegó con
 * uno (siempre en la zona de la vista). Son los que hay que usar para colisiones
 * y deltas de una mutación: en la hora repetida del otoño dos instantes
 * distintos tienen el mismo marker, y comparar markers daría una colisión falsa.
 * Son `null` en los eventos de día completo y en los que llegaron como string
 * sin offset (hora de pared "flotante").
 *
 * `hasEnd` recuerda si el evento traía un fin válido o se le puso el de por
 * defecto. La interfaz lo necesita: no es lo mismo arrastrar el borde de un
 * evento con fin que dar forma a uno que no lo tenía.
 */
final readonly class EventInstance
{
    /**
     * Estable entre renders y compartido por todos los trozos del evento (uno
     * por recurso, uno por semana): el layout lo usa en `wire:key`.
     */
    public string $instanceId;

    public function __construct(
        public CalendarEvent $event,
        public DateRange $range,
        public bool $allDay,
        public bool $hasEnd,
        public ?CarbonImmutable $exactStart = null,
        public ?CarbonImmutable $exactEnd = null,
        ?string $instanceId = null,
        /** La serie de la que sale (su id), si es una ocurrencia generada o una modificada. */
        public string|int|null $seriesId = null,
        /** El inicio ORIGINAL de la ocurrencia, en hora de pared de la vista: qué ocurrencia es, aunque se haya movido. */
        public ?Marker $recurrenceId = null,
        /** Ese mismo inicio original como instante, para que el consumidor la identifique al persistir. */
        public ?CarbonImmutable $occurrenceStart = null,
    ) {
        $this->instanceId = $instanceId ?? $event->id.':'.$range->start->ts;
    }

    /** Pertenece a una serie: hay que preguntar el alcance antes de cambiarla. */
    public function isOccurrence(): bool
    {
        return $this->seriesId !== null;
    }
}
