<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un evento visible en una columna de la rejilla horaria, con su rectángulo
 * horizontal normalizado (0..1 dentro de la columna del día).
 */
final class PlacedEvent
{
    /**
     * @param  Span  $span  La fuente de la que sale (el mismo objeto que recibió el placer).
     * @param  int  $level  Nivel lógico del kernel: la columna "base" dentro del día.
     * @param  float  $levelCoord  `nivel / nº de niveles de su componente conexa`. Todos los eventos de una componente comparten el mismo reparto base (#5004).
     * @param  float  $thickness  `(primer nivel con un colisionador − nivel) / nº de niveles`: el evento se ensancha por las columnas vacías hasta el primer evento que choca con él. Siempre mayor que 0 (#7879).
     * @param  int  $backwardDepth  Cadena más larga de colisiones hacia niveles superiores; da el z-index.
     * @param  int  $forwardDepth  Cadena más larga de colisiones hacia niveles más profundos.
     * @param  float  $left  Borde cercano, 0..1 desde el inicio de la columna (`insetInlineStart`).
     * @param  float  $right  Distancia del borde lejano al borde de la columna, 0..1 (`insetInlineEnd`).
     * @param  int  $zIndex  `backwardDepth + 1`: el CSS pide 1-based.
     * @param  bool  $marginEnd  Hay que dejar un margen final fijo para que un evento posterior que lo pisa no tape el asa de redimensionar (#6569): `slotEventOverlap` y `forwardDepth > 0`. El tamaño del margen (20 px en FC) lo decide el consumidor.
     */
    public function __construct(
        public readonly Span $span,
        public readonly int $level,
        public readonly float $levelCoord,
        public readonly float $thickness,
        public readonly int $backwardDepth,
        public readonly int $forwardDepth,
        public readonly float $left,
        public readonly float $right,
        public readonly int $zIndex,
        public readonly bool $marginEnd,
    ) {}
}
