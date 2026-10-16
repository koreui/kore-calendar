<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un tramo lateral `[start, end)` sin identidad: el resultado de intersectar,
 * restar o unir otros tramos (`LateralSpan` en span-math.ts).
 *
 * @internal
 */
final class Lateral
{
    public function __construct(
        public readonly int|float $start,
        public readonly int|float $end,
    ) {}
}
