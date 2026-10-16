<?php

namespace KoreUi\Calendar\Time;

use InvalidArgumentException;

/**
 * Las opciones que cambian qué fechas muestra una vista.
 */
final class ViewOptions
{
    /** @var list<int> */
    public readonly array $hiddenDays;

    /**
     * @param  list<int>  $hiddenDays  Días de la semana ocultos (0 = domingo).
     */
    public function __construct(
        public readonly WeekRules $weekRules = new WeekRules,
        array $hiddenDays = [],
        public readonly bool $fixedWeekCount = true,
        public readonly bool $showNonCurrentDates = true,
    ) {
        // Se copia y normaliza: mutar el array que pasó el usuario provocó un
        // bucle de render infinito en FullCalendar (#7069).
        $hidden = array_values(array_unique(array_map('intval', $hiddenDays)));
        sort($hidden);

        foreach ($hidden as $d) {
            if ($d < 0 || $d > 6) {
                throw new InvalidArgumentException("Día oculto fuera de rango: {$d}. Van de 0 (domingo) a 6 (sábado).");
            }
        }

        if (count($hidden) === 7) {
            throw new InvalidArgumentException('No se pueden ocultar los siete días de la semana.');
        }

        $this->hiddenDays = $hidden;
    }

    public function isHidden(Marker $day): bool
    {
        return in_array($day->dayOfWeek(), $this->hiddenDays, true);
    }

    /** El primer día visible desde `$day` en la dirección dada (1 o −1), incluido él. */
    public function skipHidden(Marker $day, int $direction): Marker
    {
        while ($this->isHidden($day)) {
            $day = $day->addDays($direction >= 0 ? 1 : -1);
        }

        return $day;
    }

    /** Recorta los días ocultos de los bordes del rango. */
    public function trimHidden(DateRange $range): DateRange
    {
        $start = $range->start;
        $end = $range->end;

        while ($start->ts < $end->ts && $this->isHidden($start)) {
            $start = $start->addDays(1);
        }

        while ($end->ts > $start->ts && $this->isHidden($end->addDays(-1))) {
            $end = $end->addDays(-1);
        }

        return new DateRange($start, $end);
    }
}
