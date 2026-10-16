<?php

namespace KoreUi\Calendar\ICal;

use InvalidArgumentException;
use Throwable;

/**
 * Un archivo iCalendar que no se puede leer, o (en modo estricto) un VEVENT roto.
 *
 * `icsLine` es la línea del archivo `.ics` donde empieza el problema (1 es la primera),
 * o 0 si no se puede atribuir a una línea. No se llama `line` porque `Exception::$line`
 * ya es la línea del código PHP que la lanzó. El mensaje ya lleva la línea delante
 * («Línea 12: …»): quien lo muestra no tiene que componerlo.
 */
final class ICalException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly int $icsLine = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
