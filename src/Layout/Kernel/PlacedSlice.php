<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un trozo visible de la vista mes: el evento entero en su fila, o un
 * fragmento si el kernel lo cortó (`eventSlicing`) para ocultarlo solo donde
 * estorba.
 */
final class PlacedSlice
{
    /**
     * @param  Span  $span  La fuente de la que sale.
     * @param  int|float  $start  Columna inicial del trozo (inclusiva).
     * @param  int|float  $end  Columna final del trozo (exclusiva).
     * @param  bool  $isStart  El trozo empieza donde empieza el evento de verdad: el span lo era Y el trozo no se recortó por la izquierda.
     * @param  bool  $isEnd  Ídem por el final.
     * @param  int  $level  Nivel lógico del kernel, tras el reparto de los extras.
     * @param  int  $row  Fila vertical sin medir (0-based): 0 si no choca con nada colocado, o 1 + la fila más baja de los colocados que intersecan. Es `resolveLevelCoords` con altura unitaria.
     * @param  bool  $isSlice  Es un fragmento parcial por `eventSlicing`, no el evento entero de su fila.
     * @param  string  $key  `wire:key` estable: `{span.key}:{columnaInicial}` si es entero y `{span.key}:{columnaInicial}:{inicioDelTrozo}:slice` si es parcial. El final se omite a propósito, para no remontar el nodo cuando solo cambia el final.
     */
    public function __construct(
        public readonly Span $span,
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly bool $isStart,
        public readonly bool $isEnd,
        public readonly int $level,
        public readonly int $row,
        public readonly bool $isSlice,
        public readonly string $key,
    ) {}
}
