<?php

namespace KoreUi\Calendar\Events\Sources;

use Carbon\CarbonImmutable;
use Closure;
use KoreUi\Calendar\Events\EventSource;
use UnexpectedValueException;

/**
 * Una fuente a medida: `fn (CarbonImmutable $from, CarbonImmutable $to) => [...]`.
 *
 * Para una API externa, una consulta que no es Eloquent o eventos recurrentes
 * que se expanden a mano. El rango llega en instantes reales (en la zona de la
 * vista), listo para pasarlo a donde haga falta.
 */
final class CallbackSource implements EventSource
{
    /**
     * @param  Closure(CarbonImmutable, CarbonImmutable): iterable  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $events = ($this->callback)($from, $to);

        // Un `null` por olvidar el `return` sería, si no, un calendario vacío sin
        // explicación.
        if (! is_iterable($events)) {
            throw new UnexpectedValueException(sprintf(
                'El callback de CallbackSource debe devolver un iterable de eventos (array, Collection o generador) y devolvió %s.',
                get_debug_type($events),
            ));
        }

        return $events;
    }
}
