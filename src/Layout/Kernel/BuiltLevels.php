<?php

namespace KoreUi\Calendar\Layout\Kernel;

/** Resultado de `PlacementKernel::buildLevels()`. */
final class BuiltLevels
{
    /**
     * @param  list<list<Source>>  $levels  Cada nivel, ordenado por inicio.
     * @param  list<Source>  $excluded  Los que no cupieron, en orden de evento.
     */
    public function __construct(
        public readonly array $levels,
        public readonly array $excluded,
    ) {}
}
