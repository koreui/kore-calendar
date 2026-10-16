<?php

namespace KoreUi\Calendar\Recurrence;

/**
 * Lo que queda por gastar en un render: ocurrencias que se dibujan e iteraciones de
 * php-rrule, sumadas todas las series.
 *
 * El tope por serie (`recurrence.max_occurrences`, `max_iterations`) no basta: mil series
 * con mil ocurrencias cada una son un millón. Este presupuesto es el de toda la vista
 * (`max_total_occurrences`, `max_total_iterations`) y lo comparten las llamadas a
 * `RecurrenceExpander::expand()` de un mismo `collect()`.
 *
 * @internal
 */
final class OccurrenceBudget
{
    private bool $warnedOccurrences = false;

    private bool $warnedIterations = false;

    public function __construct(private int $occurrences, private int $iterations) {}

    public function occurrences(): int
    {
        return $this->occurrences;
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function consume(int $occurrences, int $iterations): void
    {
        $this->occurrences = max(0, $this->occurrences - $occurrences);
        $this->iterations = max(0, $this->iterations - $iterations);
    }

    /** Verdadero solo la primera vez: se avisa una vez por vista, no por serie. */
    public function firstOccurrencesWarning(): bool
    {
        return ! $this->warnedOccurrences && ($this->warnedOccurrences = true);
    }

    public function firstIterationsWarning(): bool
    {
        return ! $this->warnedIterations && ($this->warnedIterations = true);
    }
}
