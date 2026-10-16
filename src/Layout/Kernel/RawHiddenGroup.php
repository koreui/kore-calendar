<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Grupo interno del acumulador de ocultos: una componente conexa (por
 * intersección estricta) de trozos ocultos, que puede contener varios
 * fragmentos de una misma fuente. La lista de grupos, ordenada por `start`,
 * hace de conjunto de cobertura para la geometría de tramos.
 *
 * @internal
 */
final class RawHiddenGroup
{
    /** @param  list<Slice>  $hiddenSlices  Sin ordenar: `HiddenGroups::finalize()` los pone en orden de evento (los fragmentos de una fuente quedan contiguos para fundirlos). */
    public function __construct(
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly array $hiddenSlices,
    ) {}
}
