<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Una columna de la fila de la vista mes: lo que lleva el enlace "+N más" y el
 * popover. Hay UN enlace por celda, no uno por grupo (docs/calendar.md §7.2).
 */
final class DayCell
{
    /**
     * @param  list<Span>  $hidden  Las fuentes ocultas que cruzan esta columna, UNA vez cada una aunque tengan varios fragmentos ocultos, en orden de evento.
     * @param  list<Span>  $all  Todas las fuentes que cruzan la columna, visibles u ocultas, en orden de evento. Es la lista del popover.
     * @param  int  $usedRows  Filas visibles que ocupa la columna (la fila más baja de lo visible que la cruza, más 1). El equivalente sin medir de `contentHeight`.
     */
    public function __construct(
        public readonly int $column,
        public readonly array $hidden,
        public readonly array $all,
        public readonly int $usedRows = 0,
    ) {}

    /** El número del enlace: se pinta solo si es mayor que cero. */
    public function hiddenCount(): int
    {
        return count($this->hidden);
    }
}
