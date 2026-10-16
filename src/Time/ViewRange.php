<?php

namespace KoreUi\Calendar\Time;

use InvalidArgumentException;

/**
 * Los rangos de fechas de una vista para una fecha dada.
 *
 * Port de la parte que nos sirve de `DateProfileGenerator` de FullCalendar:
 *
 * - `current`: el periodo nominal ("octubre", "la semana del 12").
 * - `render`: lo que se dibuja. En mes, rellenado hasta semanas completas (y a 6
 *   filas con `fixedWeekCount`); recortado de días ocultos por los bordes.
 * - `active`: donde hay eventos e interacción. Es `render`, salvo con
 *   `showNonCurrentDates = false`, que lo recorta a `current`.
 *
 * Las fuentes de eventos se consultan con `render`, no con `active`: pedir el
 * rango corto hizo desaparecer eventos de la primera y la última semana en
 * FullCalendar (#8077).
 */
final class ViewRange
{
    /**
     * Unidad de cada vista y si rellena hasta semanas completas.
     *
     * @var array<string, array{0: 'day'|'week'|'month', 1: bool}>
     */
    private const VIEWS = [
        'month' => ['month', true],
        'week' => ['week', false],
        'day' => ['day', false],
        'list' => ['week', false],
        'resource-week' => ['week', false],
        'resource-day' => ['day', false],
        'timeline-day' => ['day', false],
        'timeline-week' => ['week', false],
        'timeline-month' => ['month', false],
    ];

    private function __construct(
        public readonly string $view,
        public readonly string $unit,
        public readonly Marker $date,
        public readonly DateRange $current,
        public readonly DateRange $render,
        public readonly DateRange $active,
        private readonly ViewOptions $options,
    ) {}

    /** @return list<string> */
    public static function views(): array
    {
        return array_keys(self::VIEWS);
    }

    public static function for(string $view, Marker $date, ViewOptions $options = new ViewOptions): self
    {
        if (! isset(self::VIEWS[$view])) {
            throw new InvalidArgumentException("Vista desconocida «{$view}». Disponibles: ".implode(', ', self::views()).'.');
        }

        [$unit, $padWeeks] = self::VIEWS[$view];
        $date = $date->startOfDay();

        if ($unit === 'day') {
            // Un día oculto no se puede mostrar: se salta hacia delante.
            $date = $options->skipHidden($date, 1);
        }

        $current = self::currentRange($unit, $date, $options);
        $render = $current;

        if ($padWeeks) {
            $start = $options->weekRules->startOfWeek($current->start);
            $end = $options->weekRules->startOfWeek($current->end);
            if (! $end->equals($current->end)) {
                $end = $end->addDays(7);
            }

            if ($options->fixedWeekCount) {
                $end = $start->addDays(7 * 6);
            }

            $render = new DateRange($start, $end);
        }

        $render = $options->trimHidden($render);
        $active = $options->showNonCurrentDates ? $render : ($render->intersect($current) ?? $render);

        return new self($view, $unit, $date, $current, $render, $active, $options);
    }

    /** La fecha a la que lleva "anterior". */
    public function previous(): Marker
    {
        return $this->step(-1);
    }

    /** La fecha a la que lleva "siguiente". */
    public function next(): Marker
    {
        return $this->step(1);
    }

    /** @return list<Marker> Los días visibles de `render`, sin los ocultos. */
    public function days(): array
    {
        return array_values(array_filter(
            $this->render->eachDay(),
            fn (Marker $d) => ! $this->options->isHidden($d),
        ));
    }

    private function step(int $direction): Marker
    {
        // Se trunca al inicio de la unidad ANTES de sumar: 31-ene + 1 mes tiene
        // que llevar a febrero, no al 3 de marzo.
        $base = $this->current->start;

        $target = match ($this->unit) {
            'day' => $base->addDays($direction),
            'week' => $base->addDays(7 * $direction),
            'month' => $base->addMonths($direction),
        };

        return $this->unit === 'day' ? $this->options->skipHidden($target, $direction) : $target;
    }

    private static function currentRange(string $unit, Marker $date, ViewOptions $options): DateRange
    {
        return match ($unit) {
            'day' => DateRange::days($date, 1),
            'week' => DateRange::days($options->weekRules->startOfWeek($date), 7),
            'month' => new DateRange($date->startOfMonth(), $date->startOfMonth()->addMonths(1)),
        };
    }
}
