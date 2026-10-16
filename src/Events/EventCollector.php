<?php

namespace KoreUi\Calendar\Events;

use KoreUi\Calendar\Recurrence\RecurrenceExpander;

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
    private readonly RecurrenceExpander $expander;

    public function __construct(
        private readonly WallClock $clock,
        private readonly EventNormalizer $normalizer,
        ?int $maxOccurrences = null,
    ) {
        $this->expander = new RecurrenceExpander(
            $clock,
            $normalizer,
            $maxOccurrences ?? (function_exists('config') ? (int) config('kore-calendar.recurrence.max_occurrences', 1000) : 1000),
        );
    }

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

        // Dos pasadas: primero se recoge todo, porque una ocurrencia modificada
        // puede llegar antes que su serie, y la serie tiene que saber qué ocurrencias
        // NO generar.
        //
        // Series y excepciones se emparejan DENTRO de su fuente: los ids son de cada fuente,
        // y un `.ics` con `UID:5` no es la serie 5 de la base de datos. Indexar solo por id
        // dejaba que un feed ajeno sustituyera una serie o ocultara sus ocurrencias.
        $series = [];
        $overrides = [];
        $plain = [];
        $sourceIndex = 0;

        foreach ($sources as $source) {
            if (! $source instanceof EventSource) {
                throw new UnexpectedValueException(sprintf(
                    'EventCollector espera fuentes que implementen %s y recibió %s.',
                    EventSource::class,
                    get_debug_type($source),
                ));
            }

            foreach ($source->events($from, $to) as $item) {
                $event = $this->toEvent($source, $item);

                match (true) {
                    $event->isRecurring() => $series[$sourceIndex][(string) $event->id] = $event,
                    $event->isOverride() => $overrides[] = [$sourceIndex, $event],
                    default => $plain[] = $event,
                };
            }

            $sourceIndex++;
        }

        $instances = [];

        foreach ($plain as $event) {
            $instances[] = $this->normalizer->normalize($event);
        }

        // La modificada SUSTITUYE a la generada, no se suma (FullCalendar #6451:
        // RECURRENCE-ID duplicado). Se identifica en la representación de su serie,
        // que ha de ser de la misma fuente.
        $skip = [];
        foreach ($overrides as [$sourceIndex, $override]) {
            $parent = $series[$sourceIndex][(string) $override->recurrenceOf] ?? null;
            $key = $parent ? RecurrenceExpander::key($parent, $override->recurrenceId, $this->clock) : null;

            if ($key !== null) {
                $skip[$sourceIndex][(string) $override->recurrenceOf][$key] = true;
            }

            $normalized = $this->normalizer->normalize($override);
            $original = $this->clock->toMarker($override->recurrenceId);

            $instances[] = new EventInstance(
                event: $normalized->event,
                range: $normalized->range,
                allDay: $normalized->allDay,
                hasEnd: $normalized->hasEnd,
                exactStart: $normalized->exactStart,
                exactEnd: $normalized->exactEnd,
                instanceId: $override->recurrenceOf.':'.$original->ts,
                seriesId: $override->recurrenceOf,
                recurrenceId: $original,
                occurrenceStart: $this->clock->toInstant($original),
            );
        }

        foreach ($series as $sourceIndex => $ofSource) {
            foreach ($ofSource as $id => $event) {
                array_push($instances, ...$this->expander->expand($event, $range, $skip[$sourceIndex][$id] ?? []));
            }
        }

        // Semiabierto: el evento que acaba justo cuando empieza el rango no entra.
        return array_values(array_filter($instances, fn (EventInstance $i) => $i->range->intersects($range)));
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
