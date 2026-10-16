<?php

namespace KoreUi\Calendar\Layout\Kernel;

/** Resultado de `TimeColumnPlacer::place()`: una columna de la rejilla horaria. */
final class TimeColumnPlacement
{
    /**
     * @param  list<PlacedEvent>  $placed  En orden del DOM: por inicio y luego por orden de evento, para que los lectores de pantalla lean en orden temporal.
     * @param  list<HiddenGroup>  $hidden  Ordenados por inicio; grupos adyacentes quedan separados.
     */
    public function __construct(
        public readonly array $placed,
        public readonly array $hidden,
    ) {}
}
