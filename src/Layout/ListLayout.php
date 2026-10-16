<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;

/**
 * La agenda: un grupo por día, solo los días con eventos.
 *
 * Cada evento aparece una vez por día que ocupa, con el tipo de hora que le toca
 * ese día: un evento con hora que cruza varios días muestra «inicio – 24:00» el
 * primero, «todo el día» en medio y «00:00 – fin» el último.
 */
final class ListLayout
{
    /**
     * @param  list<array{date: Marker, isToday: bool, items: list<array{instance: EventInstance, kind: 'all-day'|'single'|'start'|'middle'|'end'}>}>  $days
     */
    private function __construct(
        public readonly ViewRange $range,
        public readonly array $days,
    ) {}

    /** @param list<EventInstance> $instances */
    public static function build(ViewRange $range, array $instances, Marker $now, LayoutOptions $options = new LayoutOptions): self
    {
        $instances = $options->order->sort(DayGridLayout::withoutBackground($instances));
        $today = $now->startOfDay();
        $days = [];

        foreach ($range->days() as $day) {
            if (! $range->active->contains($day)) {
                continue;
            }

            $dayRange = DateRange::days($day, 1);
            $items = [];

            foreach ($instances as $instance) {
                $covered = $instance->allDay ? $instance->range : DaySlicer::visibleDayRange($instance->range, $options->nextDayThreshold);

                if (! $covered->intersects($dayRange)) {
                    continue;
                }

                $items[] = ['instance' => $instance, 'kind' => self::kind($instance, $day)];
            }

            if ($items !== []) {
                $days[] = ['date' => $day, 'isToday' => $day->equals($today), 'items' => $items];
            }
        }

        return new self($range, $days);
    }

    private static function kind(EventInstance $i, Marker $day): string
    {
        if ($i->allDay) {
            return 'all-day';
        }

        $startsToday = $i->range->start->startOfDay()->equals($day);
        $endsToday = $i->range->end->ts <= $day->addDays(1)->ts;

        return match (true) {
            $startsToday && $endsToday => 'single',
            $startsToday => 'start',
            $endsToday => 'end',
            default => 'middle',
        };
    }
}
