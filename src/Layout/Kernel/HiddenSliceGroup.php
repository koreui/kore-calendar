<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un enlace "+N más" y los trozos ocultos que representa (`HiddenSliceGroup`).
 *
 * Los miembros van en orden de evento, uno por fuente oculta, y cada uno abarca
 * el casco lateral de los fragmentos ocultos de esa fuente: así el consumidor
 * deduce la continuidad (`isStart`/`isEnd`) de las coordenadas.
 */
final class HiddenSliceGroup
{
    /**
     * @param  string  $key  `Slice::key()` del primer miembro. Cambia si cambia el primer trozo, y NO depende de coordenadas.
     * @param  list<Slice>  $hiddenSlices
     */
    public function __construct(
        public readonly string $key,
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly array $hiddenSlices,
    ) {}
}
