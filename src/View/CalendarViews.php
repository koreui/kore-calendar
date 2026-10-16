<?php

namespace KoreUi\Calendar\View;

use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\ViewRange;

/** Qué vistas puede ofrecer un calendario y con qué componente se pinta cada una. */
final class CalendarViews
{
    private const RESOURCE_VIEWS = ['resource-week', 'resource-day'];

    /**
     * Las vistas pedidas que existen, sin las de recursos si no hay recursos: una
     * vista "Salas" vacía no le sirve a nadie.
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
                && ! str_starts_with($v, 'timeline')
                && ($hasResources || ! in_array($v, self::RESOURCE_VIEWS, true)),
        ));
    }

    /** El componente Blade (bajo kore::calendar.) que pinta la vista. */
    public static function component(string $view): string
    {
        return match ($view) {
            'month' => 'month',
            'list' => 'list',
            default => 'time-grid',
        };
    }
}
