<?php

namespace KoreUi\Calendar\View;

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
     */
    public static function render(CalendarSettings $settings, iterable $events = [], ?ResourceSet $resources = null): RenderedCalendar
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
            'month' => MonthLayout::build($range, $instances, $now, $options, $settings->weekNumbers),
            'list' => ListLayout::build($range, $instances, $now, $options),
            'timeline-day', 'timeline-week', 'timeline-month' => self::timeline($settings, $range, $instances, $now, $resources),
            'resource-week', 'resource-day' => TimeGridLayout::build($range, $instances, $now, $options, $resources ?? new ResourceSet([]), $settings->datesAboveResources, $settings->slotDuration),
            default => TimeGridLayout::build($range, $instances, $now, $options, null, false, $settings->slotDuration),
        };

        return new RenderedCalendar($settings, $range, $layout, $now, $resources);
    }

    private static function timeline(CalendarSettings $settings, ViewRange $range, array $instances, $now, ?ResourceSet $resources): TimelineLayout
    {
        // Solo con muchas filas se monta una ventana: por debajo, todo de una vez.
        $virtual = count(TimelineLayout::rowSkeleton($resources)) > CalendarSettings::virtualizeAfter();

        return TimelineLayout::build(
            $range, $instances, $now, $settings->layoutOptions, $resources, $settings->timelineSlot,
            $virtual ? ($settings->timelineRowStart ?? 0) : null,
            $virtual ? $settings->timelineRowLimit : null,
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
