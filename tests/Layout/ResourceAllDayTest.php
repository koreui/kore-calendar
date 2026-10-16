<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WallClock;

it('un evento de día completo con dos recursos aparece una vez en cada uno', function () {
    // El mismo evento da dos trozos en la misma fila del carril: uno por recurso.
    // Sus claves tienen que ser distintas o el kernel los confunde.
    $event = (new EventNormalizer(new WallClock('UTC')))->normalize(
        CalendarEvent::make(id: 'v', title: 'V', start: '2026-10-16', allDay: true, resourceIds: ['a', 'b'])
    );
    $resources = new ResourceSet([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']]);

    $g = TimeGridLayout::build(ViewRange::for('resource-day', Marker::parse('2026-10-16')), [$event], Marker::parse('2026-10-16'), new LayoutOptions, $resources);

    expect(array_map(fn ($s) => [$s['colStart'], $s['colEnd']], $g->allDay['slices']))->toBe([[0, 1], [1, 2]])
        ->and(count(array_unique(array_column($g->allDay['slices'], 'key'))))->toBe(2);
});

it('fecha sobre recursos: cada trozo de un evento partido tiene su clave', function () {
    $event = (new EventNormalizer(new WallClock('UTC')))->normalize(
        CalendarEvent::make(id: 'v', title: 'V', start: '2026-10-12', end: '2026-10-15', allDay: true, resourceIds: ['a'])
    );
    $resources = new ResourceSet([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']]);

    $g = TimeGridLayout::build(ViewRange::for('resource-week', Marker::parse('2026-10-16')), [$event], Marker::parse('2026-10-16'), new LayoutOptions, $resources, datesAboveResources: true);

    expect($g->allDay['slices'])->toHaveCount(3)
        ->and(count(array_unique(array_column($g->allDay['slices'], 'key'))))->toBe(3);
});
