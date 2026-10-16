<?php

namespace KoreUi\Calendar\Recurrence;

use DateTimeInterface;

/**
 * Lo que devuelve `RuleScanner::between()`.
 *
 * @internal
 */
final readonly class RuleScan
{
    /**
     * @param  list<DateTimeInterface>  $occurrences  Las del rango pedido, en orden.
     * @param  int  $iterations  Cuántas ocurrencias recorrió php-rrule desde el inicio de la regla (las anteriores al rango también cuestan).
     * @param  bool  $truncated  Se cortó por el tope de iteraciones antes de llegar al final del rango: puede faltar lo que sigue.
     */
    public function __construct(
        public array $occurrences,
        public int $iterations,
        public bool $truncated,
    ) {}
}
