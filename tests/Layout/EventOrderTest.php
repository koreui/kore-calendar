<?php

use KoreUi\Calendar\Layout\EventOrder;

$ids = fn (array $list) => array_map(fn ($i) => (string) $i->event->id, $list);

it('por defecto: inicio, luego el más largo, luego con hora antes que día completo, luego título', function () use ($ids) {
    $sorted = (new EventOrder)->sort([
        lyEvent('corto', '2026-10-12 09:00', '2026-10-12 10:00'),
        lyEvent('dia', '2026-10-12', allDay: true),
        lyEvent('largo', '2026-10-12 09:00', '2026-10-12 12:00'),
        lyEvent('antes', '2026-10-12 08:00', '2026-10-12 08:30'),
        lyEvent('b', '2026-10-13 09:00', title: 'Beta'),
        lyEvent('a', '2026-10-13 09:00', title: 'Alfa'),
    ]);

    expect($ids($sorted))->toBe(['dia', 'antes', 'largo', 'corto', 'a', 'b']);
});

it('un evento con hora va antes que uno de día completo con el mismo inicio y duración', function () use ($ids) {
    $sorted = (new EventOrder)->sort([
        lyEvent('allday', '2026-10-12', '2026-10-13', allDay: true),
        lyEvent('timed', '2026-10-12 00:00', '2026-10-13 00:00', allDay: false),
    ]);

    expect($ids($sorted))->toBe(['timed', 'allday']);
});

it('acepta campos de meta, descendentes, y deja los nulos al final', function () use ($ids) {
    $sorted = (new EventOrder('-priority,title'))->sort([
        lyEvent('x', '2026-10-12 09:00', title: 'X'),
        lyEvent('baja', '2026-10-12 09:00', title: 'B', meta: ['priority' => 1]),
        lyEvent('alta', '2026-10-12 09:00', title: 'A', meta: ['priority' => 5]),
    ]);

    expect($ids($sorted))->toBe(['alta', 'baja', 'x']);
});

it('ordena texto con reglas del idioma, no por bytes', function () use ($ids) {
    $sorted = (new EventOrder('title', 'es'))->sort([
        lyEvent('o', '2026-10-12', allDay: true, title: 'Ostra'),
        lyEvent('n', '2026-10-12', allDay: true, title: 'Ñandú'),
        lyEvent('a', '2026-10-12', allDay: true, title: 'árbol'),
    ]);

    expect($ids($sorted))->toBe(['a', 'n', 'o']);
})->skip(! class_exists(Collator::class), 'requiere ext-intl');

it('es estable: a igualdad conserva el orden de entrada', function () use ($ids) {
    $sorted = (new EventOrder('start'))->sort([
        lyEvent('1', '2026-10-12', allDay: true),
        lyEvent('2', '2026-10-12', allDay: true),
        lyEvent('3', '2026-10-12', allDay: true),
    ]);

    expect($ids($sorted))->toBe(['1', '2', '3']);
});

it('rechaza un campo vacío', function () {
    new EventOrder('start,,title');
})->throws(InvalidArgumentException::class);
