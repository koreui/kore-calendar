<?php

namespace KoreUi\Calendar\Layout\Kernel;

/** Resultado de `DayRowPlacer::place()`: una fila de la vista mes (o el carril de todo el día). */
final class DayRowPlacement
{
    /**
     * @param  list<PlacedSlice>  $visible  Orden del DOM: por columna inicial y, dentro de ella, por orden de evento.
     * @param  array<int, DayCell>  $cells  Por columna, de 0 a columnas-1.
     * @param  int  $rowCount  Filas verticales que usa lo visible (0 si no hay nada).
     * @param  list<Slice>  $hiddenSlices  Los fragmentos ocultos exactos, para auditar la partición visible+oculto. No hace falta para pintar.
     */
    public function __construct(
        public readonly array $visible,
        public readonly array $cells,
        public readonly int $rowCount = 0,
        public readonly array $hiddenSlices = [],
    ) {}
}
