<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Un enlace "+N más" de la rejilla horaria: el intervalo vertical que cubre y los
 * eventos que no cupieron en él. A diferencia de la vista mes, aquí se pinta un
 * enlace por grupo, como una superposición en `top = start`.
 */
final class HiddenGroup
{
    /**
     * @param  int|float  $start  Inicio del intervalo del grupo (la unidad de los spans).
     * @param  int|float  $end  Final, exclusivo.
     * @param  list<Span>  $spans  Los eventos ocultos, en orden de evento, una vez cada uno.
     * @param  string  $key  Sale de los datos del primer evento oculto (su `span.key`), no de coordenadas: reescalar la rejilla no vuelve a montar el popover.
     */
    public function __construct(
        public readonly int|float $start,
        public readonly int|float $end,
        public readonly array $spans,
        public readonly string $key,
    ) {}
}
