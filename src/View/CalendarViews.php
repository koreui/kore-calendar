<?php

namespace KoreUi\Calendar\View;

use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\ViewRange;

/** Qué vistas puede ofrecer un calendario y con qué componente se pinta cada una. */
final class CalendarViews
{
    private const RESOURCE_VIEWS = ['resource-week', 'resource-day', 'timeline-day', 'timeline-week', 'timeline-month'];

    /**
     * Las vistas pedidas que existen, sin las de recursos (columnas y timeline) si
     * no hay recursos: una vista "Salas" vacía no le sirve a nadie.
     *
     * @param  iterable<string>  $wanted
     * @return list<string>
     */
    public static function available(iterable $wanted, ?ResourceSet $resources): array
    {
        $hasResources = $resources !== null && ! $resources->isEmpty();

        return array_values(array_filter(
            array_unique([...$wanted]),
            fn ($v) => in_array($v, ViewRange::views(), true)
                && ($hasResources || ! in_array($v, self::RESOURCE_VIEWS, true)),
        ));
    }

    /**
     * La vista con la que arranca un calendario: el primer candidato que el calendario
     * ofrece y, si ninguno vale, la primera de las disponibles (o `month` si no hay ninguna).
     *
     * Es la cadena de <x-kore::calendar> (URL, prop `view`, `default_view`) y de
     * `KoreCalendar` (`$view`, `default_view`) en un solo sitio, para que no vuelvan a
     * discrepar: cada eslabón solo cuenta si la vista está disponible, así que un valor
     * inventado, una vista de recursos sin recursos o una `default_view` que no está en
     * `views` caen al siguiente en vez de pintar una vista que el selector no ofrece.
     *
     * @param  iterable<mixed>  $candidates  De mayor a menor prioridad; los que no son texto se saltan.
     * @param  list<string>  $available  Lo que devuelve `available()`.
     */
    public static function initial(iterable $candidates, array $available): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $available[0] ?? 'month';
    }

    /** El componente Blade (bajo kore::calendar.) que pinta la vista. */
    public static function component(string $view): string
    {
        return match ($view) {
            'month' => 'month',
            'list' => 'list',
            'timeline-day', 'timeline-week', 'timeline-month' => 'timeline',
            default => 'time-grid',
        };
    }
}
