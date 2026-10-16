<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Cobertura de un enlace "+N más" y el nivel más superficial que deja
 * inaccesible (la reserva de la "tasa" del enlace).
 *
 * @internal
 */
final class LevelSpan
{
    public function __construct(
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly int $levelIndex,
    ) {}
}
