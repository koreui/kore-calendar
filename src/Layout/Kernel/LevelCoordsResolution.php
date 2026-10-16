<?php

namespace KoreUi\Calendar\Layout\Kernel;

/** Resultado de `PlacementKernel::resolveLevelCoords()`. */
final class LevelCoordsResolution
{
    /**
     * @param  list<list<Slice>>  $placementSliceLevels  Los admitidos, reempaquetados desde cero y compactados alrededor de los pendientes y los excluidos.
     * @param  array<string, int|float>  $sliceCoords  `Slice::key()` => coordenada vertical.
     * @param  bool  $isSettled  Falso si algún trozo no tenía altura.
     * @param  list<Slice>  $excludedSlices  Los que pasaban del presupuesto.
     */
    public function __construct(
        public readonly array $placementSliceLevels,
        public readonly array $sliceCoords,
        public readonly bool $isSettled,
        public readonly array $excludedSlices,
    ) {}
}
