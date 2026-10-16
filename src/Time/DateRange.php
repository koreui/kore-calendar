<?php

namespace KoreUi\Calendar\Time;

use InvalidArgumentException;

/**
 * Un intervalo semiabierto de horas de pared: [start, end).
 *
 * Semiabierto en todo el paquete, como en FullCalendar: dos rangos que solo se
 * tocan en el borde NO se solapan, y el fin de un evento de día completo es
 * exclusivo (3-sep → 5-sep cubre el 3 y el 4).
 */
final class DateRange
{
    public function __construct(
        public readonly Marker $start,
        public readonly Marker $end,
    ) {
        if ($end->ts < $start->ts) {
            throw new InvalidArgumentException("Rango invertido: {$start} → {$end}.");
        }
    }

    public static function days(Marker $start, int $days): self
    {
        return new self($start, $start->addDays($days));
    }

    public function intersects(self $other): bool
    {
        return $this->start->ts < $other->end->ts && $other->start->ts < $this->end->ts;
    }

    public function contains(Marker $marker): bool
    {
        return $this->start->ts <= $marker->ts && $marker->ts < $this->end->ts;
    }

    public function containsRange(self $other): bool
    {
        return $this->start->ts <= $other->start->ts && $other->end->ts <= $this->end->ts;
    }

    /** La parte común, o null si no se tocan. */
    public function intersect(self $other): ?self
    {
        $start = Marker::max($this->start, $other->start);
        $end = Marker::min($this->end, $other->end);

        return $start->ts < $end->ts ? new self($start, $end) : null;
    }

    public function seconds(): int
    {
        return $this->end->ts - $this->start->ts;
    }

    /** @return list<Marker> El inicio de cada día que el rango toca. */
    public function eachDay(): array
    {
        $days = [];

        for ($d = $this->start->startOfDay(); $d->ts < $this->end->ts; $d = $d->addDays(1)) {
            $days[] = $d;
        }

        return $days;
    }

    public function equals(self $other): bool
    {
        return $this->start->equals($other->start) && $this->end->equals($other->end);
    }
}
