<?php

/*
 * Port de packages/preact/tests/seg-placement/daygrid-adapter.test.ts
 * (FullCalendar v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md).
 *
 * Qué cambia respecto al original:
 * - FullCalendar mide alturas en el navegador y sus tests pasan mapas de
 *   alturas y comprueban `contentHeight` y coordenadas en píxeles. Aquí la
 *   coordenada vertical es la fila sin medir (altura unitaria) y
 *   `contentHeight` es `DayCell::$usedRows`. Los valores que no dependen de
 *   medir (claves, ocultos, flags) se conservan tal cual.
 * - La opción booleana `true` (modo "auto") no existe: se omiten sus casos.
 *
 * No se portan (10 + 1 casos, todos dependen de medir en píxeles):
 *   describe 'DayGrid row-local placement state'
 *     - 'estimates the candidate frontier from current canvas and slice heights'
 *   describe 'DayGrid kernel pixel placement' (los 10 casos, llaman a
 *   `buildDayGridPixelPlacements` / `buildPixelLimitedLayout`)
 * y, dentro de los casos portados, solo las expectativas con `true`:
 *   - 'uses explicit numeric caps, the auto frontier...': 2 de 5 (modo 'auto')
 *   - 'reproduces production option precedence...': 2 de 6 (`true`)
 *   - 'charges a logical level...': 1 de 4 (modo 'auto')
 */

use KoreUi\Calendar\Layout\Kernel\DayRowPlacer;
use KoreUi\Calendar\Layout\Kernel\Span;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

// DayGrid production placement adapter > builds integer-column sources with stable production identity and order
it('construye fuentes de columnas enteras con identidad y orden estables', function () {
    $first = Fixtures::span('first', 1, 3, isEnd: false);
    $second = Fixtures::span('second', 0, 1, isStart: false);
    $sources = DayRowPlacer::sources([$first, $second]);

    expect($sources)->toHaveCount(2)
        ->and([$sources[0]->key, $sources[0]->start, $sources[0]->end, $sources[0]->isStart, $sources[0]->isEnd, $sources[0]->orderIndex])
        ->toBe(['first:1', 1, 3, true, false, 0])
        ->and([$sources[1]->key, $sources[1]->start, $sources[1]->end, $sources[1]->isStart, $sources[1]->isEnd, $sources[1]->orderIndex])
        ->toBe(['second:0', 0, 1, false, true, 1])
        // El span original viaja intacto (`sources[0].eventRange` toBe `first.eventRange`).
        ->and($sources[0]->payload)->toBe($first)
        ->and($sources[1]->payload)->toBe($second);
});

// DayGrid row-local placement state > uses explicit numeric caps, the auto frontier, and no cap for unlimited rows
it('usa topes numéricos explícitos y ninguno en las filas sin límite', function () {
    expect(DayRowPlacer::candidateMaxLevels('unlimited', null, null))->toBe(INF)
        ->and(DayRowPlacer::candidateMaxLevels('maxEvents', 3, 7))->toBe(3)
        ->and(DayRowPlacer::candidateMaxLevels('maxEventRows', null, 4))->toBe(4);
});

// DayGrid screen mode routing > reproduces production option precedence, including boolean over numeric
it('reproduce la precedencia de opciones de FullCalendar', function () {
    expect(DayRowPlacer::mode(null, null))->toBe('unlimited')
        ->and(DayRowPlacer::mode(2, null))->toBe('maxEvents')
        ->and(DayRowPlacer::mode(null, 2))->toBe('maxEventRows')
        // Si vienen los dos, gana `dayMaxEvents`.
        ->and(DayRowPlacer::mode(2, 3))->toBe('maxEvents');
});

// DayGrid screen mode routing > charges a logical level for a more link only under dayMaxEventRows
it('cobra un nivel lógico al enlace "+N más" solo bajo dayMaxEventRows', function () {
    expect(DayRowPlacer::moreLinkLevelTax('unlimited'))->toBe(0)
        ->and(DayRowPlacer::moreLinkLevelTax('maxEvents'))->toBe(0)
        ->and(DayRowPlacer::moreLinkLevelTax('maxEventRows'))->toBe(1);
});

// DayGrid kernel level placement > keeps unmeasured unlimited slices mounted but unpositioned
// (adaptado: sin medir no hay "sin posición"; se conservan las claves y que no hay ocultos)
it('mantiene todos los trozos de una fila sin límite y no oculta ninguno', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('first', 0, 1),
        Fixtures::span('second', 0, 1),
    ], 1);

    expect(Fixtures::visibleKeys($placement, 0))->toBe(['first:0', 'second:0'])
        ->and($placement->cells[0]->hidden)->toBe([])
        ->and($placement->visible[0]->row)->toBe(0);
});

// DayGrid kernel level placement > mounts unmeasured partial slices without positioning them
// (adaptado: las alturas de 12 y 18 px no existen; la posición es una fila sin medir)
it('conserva los trozos parciales en la columna donde empiezan', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('blocker', 1, 2),
        Fixtures::span('wide', 0, 3),
    ], 3, 1);

    expect(Fixtures::visibleKeys($placement, 0))->toBe(['wide:0:0:slice'])
        ->and(Fixtures::visibleKeys($placement, 1))->toBe(['blocker:1'])
        ->and(Fixtures::visibleKeys($placement, 2))->toBe(['wide:0:2:slice'])
        // Las posiciones del original (0 y 0) siguen valiendo sin medir.
        ->and($placement->visible[0]->row)->toBe(0)
        ->and($placement->visible[1]->row)->toBe(0);
});

// DayGrid kernel level placement > projects hidden glob slices to ordered, real-boundary cell segs
it('proyecta los ocultos a las celdas, en orden y con los bordes reales', function () {
    $blocker = Fixtures::span('blocker', 1, 2);
    $wide = Fixtures::span('wide', 0, 3);
    $later = Fixtures::span('later', 1, 2);
    $placement = DayRowPlacer::place([$blocker, $wide, $later], 3, 1);

    expect(array_map(Fixtures::hiddenIds(...), $placement->cells))->toBe([
        0 => [],
        1 => ['wide', 'later'],
        2 => [],
    ])
        ->and(Fixtures::ids($placement->cells[1]->all))->toBe(['blocker', 'wide', 'later']);

    // `columns[1].hiddenSegs[0]` es `wide` recortado a la celda: ni empieza ni acaba aquí.
    $cut = DayRowPlacer::cutToColumn($placement->cells[1]->hidden[0], 1);

    expect([$cut->key, $cut->start, $cut->end, $cut->isStart, $cut->isEnd])->toBe(['wide', 1, 2, false, false]);
});

// DayGrid kernel level placement > charges the more-link occupant only in numeric rows mode
it('cobra al ocupante el enlace solo en el modo de filas numéricas', function () {
    $stack = [
        Fixtures::span('first', 0, 1),
        Fixtures::span('second', 0, 1),
        Fixtures::span('third', 0, 1),
    ];
    $eventsLayout = DayRowPlacer::place($stack, 1, 2);
    $rowsLayout = DayRowPlacer::place($stack, 1, null, 2);

    expect(Fixtures::visibleKeys($eventsLayout, 0))->toBe(['first:0', 'second:0'])
        ->and(Fixtures::visibleKeys($rowsLayout, 0))->toBe(['first:0'])
        ->and(Fixtures::hiddenIds($rowsLayout->cells[0]))->toBe(['second', 'third'])
        // `contentHeight` toBe EVENT_HEIGHT: una sola fila ocupada.
        ->and($rowsLayout->cells[0]->usedRows)->toBe(1);
});

// --- Contrato propio (no está en FullCalendar) -------------------------------------------------

it('recorta un span a una celda sin tocar los bordes reales del evento', function () {
    $span = new Span('multi', 2, 5, 'payload');

    $first = DayRowPlacer::cutToColumn($span, 2);
    $middle = DayRowPlacer::cutToColumn($span, 3);
    $last = DayRowPlacer::cutToColumn($span, 4);

    expect([$first->start, $first->end, $first->isStart, $first->isEnd])->toBe([2, 3, true, false])
        ->and([$middle->isStart, $middle->isEnd])->toBe([false, false])
        ->and([$last->isStart, $last->isEnd])->toBe([false, true])
        ->and($last->payload)->toBe('payload');
});

it('respeta isStart e isEnd del span: un evento recortado por la fila nunca los recupera', function () {
    $span = new Span('cut', 0, 3, null, isStart: false, isEnd: false);
    $placement = DayRowPlacer::place([$span], 3);

    expect($placement->visible)->toHaveCount(1)
        ->and([$placement->visible[0]->isStart, $placement->visible[0]->isEnd])->toBe([false, false])
        ->and(DayRowPlacer::cutToColumn($span, 0)->isStart)->toBeFalse()
        ->and(DayRowPlacer::cutToColumn($span, 2)->isEnd)->toBeFalse();
});

it('da a cada trozo una clave estable y marca los fragmentos', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('blocker', 1, 2),
        Fixtures::span('wide', 0, 3),
    ], 3, 1);
    $byKey = [];
    foreach ($placement->visible as $slice) {
        $byKey[$slice->key] = $slice;
    }

    expect($byKey['blocker:1']->isSlice)->toBeFalse()
        ->and($byKey['wide:0:0:slice']->isSlice)->toBeTrue()
        ->and([$byKey['wide:0:0:slice']->start, $byKey['wide:0:0:slice']->end])->toBe([0, 1])
        ->and([$byKey['wide:0:0:slice']->isStart, $byKey['wide:0:0:slice']->isEnd])->toBe([true, false])
        ->and([$byKey['wide:0:2:slice']->isStart, $byKey['wide:0:2:slice']->isEnd])->toBe([false, true])
        ->and($byKey['wide:0:2:slice']->span->key)->toBe('wide');
});

it('coloca la fila vertical debajo de todo lo que intersecan los ya colocados', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('a', 0, 2),
        Fixtures::span('b', 1, 3),
        Fixtures::span('c', 2, 3),
        Fixtures::span('d', 3, 4),
    ], 4);
    $rows = [];
    foreach ($placement->visible as $slice) {
        $rows[$slice->span->key] = $slice->row;
    }

    // b cruza a y baja a la fila 1. c y d no cruzan a (acaba en la columna 2):
    // caen en el primer nivel libre, el 0, aunque b esté justo encima de c.
    expect($rows)->toBe(['a' => 0, 'b' => 1, 'c' => 0, 'd' => 0])
        ->and($placement->rowCount)->toBe(2);
});

it('rechaza un span que sale de la fila, vacío, con columnas no enteras o con clave repetida', function () {
    expect(fn () => DayRowPlacer::place([Fixtures::span('a', 0, 8)], 7))
        ->toThrow(InvalidArgumentException::class, 'sale de las 7 columnas')
        ->and(fn () => DayRowPlacer::place([Fixtures::span('a', 3, 3)], 7))
        ->toThrow(InvalidArgumentException::class, 'vacío')
        ->and(fn () => DayRowPlacer::place([new Span('a', 0.5, 2)], 7))
        ->toThrow(InvalidArgumentException::class, 'no enteras')
        ->and(fn () => DayRowPlacer::place([Fixtures::span('a', 0, 1), Fixtures::span('a', 0, 2)], 7))
        ->toThrow(InvalidArgumentException::class, 'comparten la clave')
        ->and(fn () => DayRowPlacer::place([], 0))
        ->toThrow(InvalidArgumentException::class, 'al menos una columna');
});

it('acepta el mismo id en columnas de inicio distintas y un maxEvents de 0 oculta todo', function () {
    // La clave de la fuente lleva la columna de inicio: el mismo id en dos columnas no choca.
    $placement = DayRowPlacer::place([Fixtures::span('a', 0, 1), Fixtures::span('a', 1, 2)], 2, 0);

    expect($placement->visible)->toBe([])
        ->and($placement->cells[0]->hiddenCount())->toBe(1)
        ->and($placement->cells[1]->hiddenCount())->toBe(1);
});
