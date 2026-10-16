<?php

namespace KoreUi\Calendar\Events\Sources;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventSource;
use UnexpectedValueException;

/**
 * Eventos desde una consulta Eloquent: la query filtra por rango y `$map`
 * convierte cada modelo en un `CalendarEvent` (o en un array para `fromArray`).
 *
 * La consulta que se pasa es el punto de partida (permisos, tenant, un
 * calendario concreto); el filtro por rango se le añade en cada llamada. Con un
 * `Builder`, se clona antes de tocarlo: si no, las condiciones de un render se
 * acumularían en el siguiente. Con un `Closure`, se llama cada vez y devuelve
 * una consulta nueva (útil si depende del usuario autenticado).
 */
final class EloquentSource implements EventSource
{
    /**
     * @param  Builder|Closure(): Builder  $query
     * @param  Closure(\Illuminate\Database\Eloquent\Model): (CalendarEvent|array<string, mixed>)  $map
     * @param  ?string  $endColumn  `null` si el modelo solo tiene inicio.
     */
    public function __construct(
        private readonly Builder|Closure $query,
        private readonly Closure $map,
        private readonly string $startColumn = 'starts_at',
        private readonly ?string $endColumn = 'ends_at',
    ) {}

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $query = $this->query instanceof Closure ? ($this->query)() : clone $this->query;

        if (! $query instanceof Builder) {
            throw new UnexpectedValueException(sprintf(
                'El Closure de EloquentSource debe devolver un Illuminate\Database\Eloquent\Builder y devolvió %s.',
                get_debug_type($query),
            ));
        }

        self::constrain($query, $this->startColumn, $this->endColumn, $from, $to);

        $events = [];

        foreach ($query->get() as $model) {
            $events[] = ($this->map)($model);
        }

        return $events;
    }

    /**
     * Deja en la consulta solo las filas que PUEDEN intersecar [from, to). La
     * comparten el scope `overlapping` del trait y esta fuente, para que no
     * puedan divergir.
     *
     * - Con fin: `start < to AND end > from`. Semiabierto: una fila que termina
     *   justo en `from` o empieza justo en `to` no entra.
     * - Sin fin (columna NULL): el evento durará lo que dure el valor por
     *   defecto (1 h con hora, 1 día completo), así que cuenta si empieza como
     *   mucho un día antes de `from`.
     * - Sin columna de fin: todo el modelo está en el segundo caso.
     *
     * Es un filtro grueso a propósito: tiene que sobrar, nunca faltar. Quien
     * decide si de verdad interseca es `EventCollector`, ya normalizado.
     *
     * Las fechas se convierten a la zona de PHP antes de ligarlas. Laravel
     * formatea un DateTime en SU zona, sin convertir, y guarda las fechas en la
     * de la aplicación: ligar un 00:00 de Madrid tal cual compararía contra una
     * columna en UTC con dos horas de error.
     */
    public static function constrain(
        Builder $query,
        string $startColumn,
        ?string $endColumn,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): void {
        $zone = date_default_timezone_get();
        $from = CarbonImmutable::instance($from)->setTimezone($zone);
        $to = CarbonImmutable::instance($to)->setTimezone($zone);
        $earliest = $from->subDay();

        // Con el nombre de tabla delante: una consulta con join no debe dar
        // "ambiguous column".
        $start = $query->qualifyColumn($startColumn);
        $end = $endColumn === null ? null : $query->qualifyColumn($endColumn);

        // Agrupado: el `OR` de dentro no debe mezclarse con las condiciones que ya
        // traía la consulta.
        $query->where(function ($group) use ($start, $end, $from, $to, $earliest) {
            $group->where($start, '<', $to);

            if ($end === null) {
                $group->where($start, '>=', $earliest);

                return;
            }

            $group->where(function ($either) use ($start, $end, $from, $earliest) {
                $either->where($end, '>', $from)
                    ->orWhere(fn ($open) => $open->whereNull($end)->where($start, '>=', $earliest));
            });
        });
    }
}
