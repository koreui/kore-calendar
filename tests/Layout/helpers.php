<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/** Una instancia normalizada en UTC, para tests de layout. Prefijo `ly` para no chocar con otros helpers globales. */
function lyEvent(string|int $id, string $start, ?string $end = null, ?bool $allDay = null, string $title = '', array $meta = []): EventInstance
{
    return (new EventNormalizer(new WallClock('UTC')))->normalize(
        CalendarEvent::make(id: $id, title: $title ?: (string) $id, start: $start, end: $end, allDay: $allDay, meta: $meta)
    );
}

/** @return list<Marker> */
function lyDays(string $from, int $count): array
{
    $d = Marker::parse($from);

    return array_map(fn ($i) => $d->addDays($i), range(0, $count - 1));
}
