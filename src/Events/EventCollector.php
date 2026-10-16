<?php

namespace KoreUi\Calendar\Events;

use InvalidArgumentException;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\WallClock;
use UnexpectedValueException;

/**
 * Pide a las fuentes los eventos de un rango y devuelve los que de verdad caen
 * en él, ya normalizados.
 *
 * El rango que se le pasa es el de la rejilla (con los días de relleno del mes),
 * no el activo: pedir solo el activo hizo que FullCalendar perdiera eventos de
 * la primera y la última semana (#8077).
 */
final class EventCollector
{
    public function __construct(
        private readonly WallClock $clock,
        private readonly EventNormalizer $normalizer,
    ) {}

    /**
     * Sin ordenar: el orden (`eventOrder`) es del layout, que lo aplica sobre
     * los segmentos ya cortados.
     *
     * @param  iterable<EventSource>  $sources
     * @return list<EventInstance>
     */
    public function collect(iterable $sources, DateRange $range): array
    {
        // Las fuentes hablan en instantes reales; la rejilla, en horas de pared.
        $from = $this->clock->toInstant($range->start);
        $to = $this->clock->toInstant($range->end);

        $instances = [];

        foreach ($sources as $source) {
            if (! $source instanceof EventSource) {
                throw new UnexpectedValueException(sprintf(
                    'EventCollector espera fuentes que implementen %s y recibió %s.',
                    EventSource::class,
                    get_debug_type($source),
                ));
            }

            foreach ($source->events($from, $to) as $item) {
                $instance = $this->normalizer->normalize($this->toEvent($source, $item));

                // Semiabierto: el evento que acaba justo cuando empieza el rango no entra.
                if ($instance->range->intersects($range)) {
                    $instances[] = $instance;
                }
            }
        }

        return $instances;
    }

    private function toEvent(EventSource $source, mixed $item): CalendarEvent
    {
        if ($item instanceof CalendarEvent) {
            return $item;
        }

        if (! is_array($item)) {
            throw new UnexpectedValueException(sprintf(
                'La fuente %s devolvió %s; se espera un CalendarEvent o un array.',
                $source::class,
                get_debug_type($item),
            ));
        }

        try {
            return CalendarEvent::fromArray($item);
        } catch (InvalidArgumentException $e) {
            // Dice qué fuente lo produjo: con varias, es lo primero que se pregunta.
            throw new InvalidArgumentException('La fuente '.$source::class.' devolvió un evento no válido. '.$e->getMessage(), 0, $e);
        }
    }
}
