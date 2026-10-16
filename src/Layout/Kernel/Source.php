<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Lo que el kernel exige de un segmento de entrada (`SourceSeg` en kernel.ts).
 *
 * Una fuente tiene identidad (`key`) y orden de evento (`orderIndex`); la
 * geometría que el kernel reparte sale de `start`/`end`. Los adaptadores
 * (`DayRowPlacer`, `TimeColumnPlacer`) la construyen a partir de un `Span`;
 * `payload` guarda lo que haga falta recuperar al final (el `Span` original).
 *
 * `key` es la clave de la fuente ENTERA. La de un trozo parcial se deriva de
 * ella (`Slice::key()`), así que dos fuentes de un mismo lote no pueden
 * compartirla.
 *
 * Se compara por identidad de objeto: el kernel pregunta "¿es el mismo
 * segmento?" con `===` (`piece.sourceSeg === source` en los tests de FC).
 */
final class Source
{
    public function __construct(
        public readonly string $key,
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly bool $isStart = true,
        public readonly bool $isEnd = true,
        public readonly int $orderIndex = 0,
        public readonly mixed $payload = null,
    ) {}
}
