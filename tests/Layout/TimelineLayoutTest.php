<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Layout\TimelineLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WallClock;

$now = Marker::parse('2026-10-16 10:30');
$ev = fn (string|int $id, string $start, string $end, array $res, ?bool $allDay = null) => (new EventNormalizer(new WallClock('UTC')))
    ->normalize(CalendarEvent::make(id: $id, title: (string) $id, start: $start, end: $end, allDay: $allDay, resourceIds: $res));
$rooms = fn () => new ResourceSet([
    ['id' => 'a', 'title' => 'Ana', 'group' => 'Ventas'],
    ['id' => 'b', 'title' => 'Bea', 'group' => 'Ventas'],
    ['id' => 'c', 'title' => 'Carlos', 'group' => 'Soporte'],
]);
$byKey = fn (TimelineLayout $t) => collect($t->rows)->keyBy('key');

it('un evento ocupa su tramo del eje en porcentaje', function () use ($now, $ev, $rooms, $byKey) {
    // Día de 24 ranuras de 1 h; de 06:00 a 12:00 = 25 % de inicio y 25 % de ancho.
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [$ev(1, '2026-10-16 06:00', '2026-10-16 12:00', ['a'])], $now, new LayoutOptions, $rooms(), 3600);
    $item = $byKey($t)['r:a']['events'][0];

    expect($t->slots)->toHaveCount(24)
        ->and([$item['left'], $item['width'], $item['level']])->toBe([25.0, 25.0, 0])
        ->and($byKey($t)['r:b']['events'])->toBe([]);
});

it('dos eventos que chocan en la misma fila van en subfilas distintas y la fila crece', function () use ($now, $ev, $rooms, $byKey) {
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [
        $ev(1, '2026-10-16 08:00', '2026-10-16 12:00', ['a']),
        $ev(2, '2026-10-16 10:00', '2026-10-16 14:00', ['a']),
        $ev(3, '2026-10-16 12:00', '2026-10-16 13:00', ['a']),
    ], $now, new LayoutOptions, $rooms(), 3600);
    $row = $byKey($t)['r:a'];

    expect($row['levels'])->toBe(2)
        ->and(collect($row['events'])->mapWithKeys(fn ($e) => [(string) $e['instance']->event->id => $e['level']])->all())->toBe(['1' => 0, '2' => 1, '3' => 0]);
});

it('agrupa por group, en el orden de su primera aparición', function () use ($now, $rooms) {
    $t = TimelineLayout::build(ViewRange::for('timeline-week', Marker::parse('2026-10-16')), [], $now, new LayoutOptions, $rooms(), 86400);

    expect(array_column($t->rows, 'key'))->toBe(['g:Ventas', 'r:a', 'r:b', 'g:Soporte', 'r:c'])
        ->and($t->slots)->toHaveCount(7);
});

it('un evento con varios recursos aparece en cada fila', function () use ($now, $ev, $rooms, $byKey) {
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [$ev(1, '2026-10-16 09:00', '2026-10-16 10:00', ['a', 'c'])], $now, new LayoutOptions, $rooms(), 3600);

    expect($byKey($t)['r:a']['events'])->toHaveCount(1)->and($byKey($t)['r:c']['events'])->toHaveCount(1);
});

it('recorta al eje y pierde inicio o fin', function () use ($now, $ev, $rooms, $byKey) {
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [$ev(1, '2026-10-15 20:00', '2026-10-16 06:00', ['a'])], $now, new LayoutOptions, $rooms(), 3600);
    $item = $byKey($t)['r:a']['events'][0];

    expect([$item['left'], $item['width'], $item['isStart'], $item['isEnd']])->toBe([0.0, 25.0, false, true]);
});

it('un evento muy corto no queda invisible: mínimo un cuarto de ranura', function () use ($now, $ev, $rooms, $byKey) {
    $t = TimelineLayout::build(ViewRange::for('timeline-week', Marker::parse('2026-10-16')), [$ev(1, '2026-10-14 10:00', '2026-10-14 10:01', ['a'])], $now, new LayoutOptions, $rooms(), 86400);

    // 1/4 de día en una semana de 7 días = 3,5714 %.
    expect($byKey($t)['r:a']['events'][0]['width'])->toBe(3.5714);
});

it('sin recursos, una sola fila con todo', function () use ($now) {
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [lyEvent(1, '2026-10-16 09:00', '2026-10-16 10:00')], $now);

    expect($t->rows)->toHaveCount(1)->and($t->rows[0]['events'])->toHaveCount(1);
});

it('el indicador de ahora en porcentaje del eje', function () use ($now, $rooms) {
    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [], $now, new LayoutOptions, $rooms(), 3600);

    expect($t->nowLeft)->toBe(43.75);
});

it('virtualiza: solo la ventana de filas, con la cuenta de las de fuera', function () use ($now) {
    $many = new ResourceSet(array_map(fn ($i) => ['id' => "r{$i}", 'title' => sprintf('Recurso %03d', $i)], range(1, 300)));

    $t = TimelineLayout::build(ViewRange::for('timeline-day', Marker::parse('2026-10-16')), [], $now, new LayoutOptions, $many, 3600, rowStart: 100, rowLimit: 50);

    expect($t->rows)->toHaveCount(50)
        ->and($t->rowsBefore)->toBe(100)
        ->and($t->rowsAfter)->toBe(150)
        ->and($t->totalRows)->toBe(300)
        ->and($t->rows[0]['resource']->title)->toBe('Recurso 101');
});
