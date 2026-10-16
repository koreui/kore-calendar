<?php

namespace KoreUi\Calendar\Layout\Kernel;

/** Resultado de `PlacementKernel::placeExtras()`. */
final class ExtraSlicePlacement
{
    /**
     * @param  list<list<Slice>>  $sliceLevels  Topología final, sin los ocultos.
     * @param  list<Slice>  $hiddenSlices  Pertenencia plana de los ocultos, sin orden garantizado.
     * @param  list<Slice>  $addedSlices  Trozos del resultado que no estaban en los niveles de entrada.
     */
    public function __construct(
        public readonly array $sliceLevels,
        public readonly array $hiddenSlices,
        public readonly array $addedSlices,
    ) {}
}
