<?php

namespace KoreUi\Calendar\View;

use KoreUi\Calendar\Availability\ConflictFinder;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Layout\ListLayout;
use KoreUi\Calendar\Layout\MonthLayout;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Layout\TimelineLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\ViewRange;

/**
 * Ajustes + fuentes + recursos → el layout de la vista pedida.
 *
 * Es todo el pipeline de la spec (§4.1) en un sitio: rango de la vista, eventos
 * del rango DIBUJADO (no del activo, FullCalendar #8077), normalizados en la zona
 * de la vista, y el layout que toca. Las vistas Blade solo pintan lo que sale de aquí.
 */
final class CalendarRenderer
{
    /**
     * @param  iterable<EventSource>|iterable<mixed>  $events  Fuentes, o una lista de eventos (CalendarEvent o arrays).
     * @param  bool  $virtualize  Montar solo una ventana de filas del timeline cuando hay más de `timeline.virtualize_after`.
     *                            Solo tiene sentido con un servidor al que pedir la ventana siguiente (KoreCalendar); sin él
     *                            (`<x-kore::calendar>` por enlaces) las filas de fuera no se podrían cargar nunca.
     * @param  string|null  $id  El id del componente de Livewire que lo pinta, para los ids que tienen que ser únicos por calendario.
     */
    public static function render(CalendarSettings $settings, iterable $events = [], ?ResourceSet $resources = null, bool $virtualize = true, ?string $id = null): RenderedCalendar
    {
        $range = ViewRange::for($settings->view, $settings->date, $settings->viewOptions);
        $now = $settings->clock->now();

        $collector = new EventCollector(
            $settings->clock,
            new EventNormalizer($settings->clock, $settings->defaultTimedSeconds, $settings->defaultAllDayDays),
        );

        $instances = $collector->collect(self::sources($events), $range->render);
        $options = $settings->layoutOptions;

        $layout = match ($settings->view) {
            'month' => MonthLayout::build($range, $instances, $now, $options, $settings->weekNumbers, $settings->businessHours),
            'list' => ListLayout::build($range, $instances, $now, $options),
            'timeline-day', 'timeline-week', 'timeline-month' => self::timeline($settings, $range, $instances, $now, $resources, $virtualize),
            'resource-week', 'resource-day' => TimeGridLayout::build($range, $instances, $now, $options, $resources ?? new ResourceSet([]), $settings->datesAboveResources, $settings->slotDuration, $settings->businessHours),
            default => TimeGridLayout::build($range, $instances, $now, $options, null, false, $settings->slotDuration, $settings->businessHours),
        };

        // Los conflictos se buscan entre los eventos de TODO el rango dibujado, no solo los visibles
        // en la vista: en un "+N más" o en otra columna también están, y el aviso va por evento.
        $conflicts = $settings->highlightConflicts ? ConflictFinder::byInstance(ConflictFinder::find($instances)) : [];

        return new RenderedCalendar($settings, $range, $layout, $now, $resources, $conflicts, $id);
    }

    private static function timeline(CalendarSettings $settings, ViewRange $range, array $instances, $now, ?ResourceSet $resources, bool $virtualize): TimelineLayout
    {
        // Solo con muchas filas se monta una ventana: por debajo, todo de una vez. Y solo
        // si hay quien la sirva: sin servidor se pintan todas, aunque sean muchas.
        $virtual = $virtualize && count(TimelineLayout::rowSkeleton($resources)) > CalendarSettings::virtualizeAfter();

        return TimelineLayout::build(
            $range, $instances, $now, $settings->layoutOptions, $resources, $settings->timelineSlot,
            $virtual ? ($settings->timelineRowStart ?? 0) : null,
            $virtual ? $settings->timelineRowLimit : null,
            $settings->businessHours,
        );
    }

    /** @return list<EventSource> */
    private static function sources(iterable $events): array
    {
        $sources = [];
        $loose = [];

        foreach ($events as $item) {
            $item instanceof EventSource ? $sources[] = $item : $loose[] = $item;
        }

        if ($loose !== []) {
            $sources[] = new ArraySource($loose);
        }

        return $sources;
    }
}
