<?php

/*
 * Port de packages/preact/tests/seg-placement/minimal-footprint.test.ts
 * (FullCalendar v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md): las regresiones de la
 * vista mes #5883, #5790, #7447 y "mejor mitad", con los valores esperados del
 * original.
 *
 * Aquí viven estos casos y no en daygrid-adapter.test.ts: es donde los tiene
 * FullCalendar.
 *
 * No se portan los 2 casos del bloque 'measured pruning, pixel currency'
 * (#7573, 'rescues the part of a link-band intruder outside the link span' y
 * 'rescues the intruder under exact fractional measurements too'): llaman a
 * `buildDayGridPixelPlacements`, que poda por píxeles medidos en el navegador.
 */

use KoreUi\Calendar\Layout\Kernel\DayRowPlacer;
use KoreUi\Calendar\Layout\Kernel\Span;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

/**
 * `layoutLevelRow()` del original: cada columna con sus claves visibles y sus ocultos.
 *
 * @param  list<Span>  $spans
 * @return array{visible: list<list<string>>, hidden: list<list<string>>}
 */
function minimalFootprintRow(array $spans, int $columns, ?int $maxEvents = null, ?int $maxEventRows = null): array
{
    $placement = DayRowPlacer::place($spans, $columns, $maxEvents, $maxEventRows);

    return [
        'visible' => array_map(fn (int $c): array => Fixtures::visibleKeys($placement, $c), range(0, $columns - 1)),
        'hidden' => array_map(fn ($cell): array => Fixtures::hiddenIds($cell), $placement->cells),
    ];
}

// https://github.com/fullcalendar/fullcalendar/issues/5883
// slice-plan hiding, level currency > peels a wide overflow and an evicted occupant around one full day
it('#5883: pela un desbordamiento ancho y a un ocupante desalojado alrededor de un día lleno', function () {
    // Columnas de la semana: el día 20 = col 2, el 21 = col 3, el 22 = col 4.
    $row = minimalFootprintRow([
        Fixtures::span('b1', 2, 4),
        Fixtures::span('b2', 3, 4),
        Fixtures::span('b3', 2, 5),
        Fixtures::span('b4', 2, 5),
    ], 7, null, 3);

    expect(array_values($row['hidden']))->toBe([
        [], [], [], ['b3', 'b4'], [], [], [],
    ])->and($row['visible'])->toBe([
        [], [],
        ['b1:2', 'b3:2:2:slice', 'b4:2:2:slice'],
        ['b2:3'],
        ['b3:2:4:slice', 'b4:2:4:slice'],
        [], [],
    ]);
});

// https://github.com/fullcalendar/fullcalendar/issues/5790
// slice-plan hiding, level currency > confines the more link to the one over-full day
it('#5790: confina el enlace "+N más" al único día lleno', function () {
    // Fila de un mes; el 2 de septiembre = col 3.
    $row = minimalFootprintRow([
        Fixtures::span('a', 0, 5),
        Fixtures::span('b', 1, 4),
        Fixtures::span('c', 2, 5),
        Fixtures::span('d', 3, 5),
        Fixtures::span('e', 3, 5),
    ], 7, null, 4);

    expect(array_values($row['hidden']))->toBe([
        [], [], [], ['d', 'e'], [], [], [],
    ])->and($row['visible'])->toBe([
        ['a:0'],
        ['b:1'],
        ['c:2'],
        [],
        ['d:3:4:slice', 'e:3:4:slice'],
        [], [],
    ]);
});

// The dayMaxEvents:4 issue-7447 fixture: Sat (col 6) has free levels that overflowed
// Fri-reaching events must fill instead of globbing into Sat.
// slice-plan hiding, level currency > fills a trailing column gap that a full neighbor must not mask
it('#7447: rellena un hueco en la última columna que un vecino lleno no debe enmascarar', function () {
    // Semana del 9 al 15 de abril de 2023; cols: dom 9 = 0 ... sáb 15 = 6.
    $row = minimalFootprintRow([
        Fixtures::span('g', 0, 5),
        Fixtures::span('c', 0, 6),
        Fixtures::span('h', 0, 6),
        Fixtures::span('d', 2, 5),
        Fixtures::span('j', 3, 6),
        Fixtures::span('b', 4, 7),
        Fixtures::span('f', 4, 7),
        Fixtures::span('i', 4, 6),
        Fixtures::span('a', 5, 7),
        Fixtures::span('e', 5, 7),
    ], 7, 4);

    expect(array_values($row['hidden']))->toBe([
        [], [], [],
        ['j'],
        ['j', 'b', 'f', 'i'],
        ['j', 'b', 'f', 'i'],
        [],
    ])->and($row['visible'][6])->toBe(['b:4:6:slice', 'f:4:6:slice']);
});

// Un plan de cortes se compromete con un solo nivel, así que un tramo cuyas mitades
// caben solo en niveles disjuntos conserva la mitad mejor puntuada y oculta la otra.
// El motor antiguo, con su corte voraz por prefijos, mostraba las dos mitades; el
// reempaquetado seguro cambia esa completitud por la sencillez del plan.
// slice-plan hiding, level currency > keeps the best half of a run whose halves fit only at different levels
it('"mejor mitad": conserva la mitad mejor puntuada de un tramo cuyas mitades caben en niveles distintos', function () {
    $row = minimalFootprintRow([
        Fixtures::span('m', 0, 1),
        Fixtures::span('w', 2, 3),
        Fixtures::span('n', 1, 3),
        Fixtures::span('z', 0, 2),
    ], 3, 2);

    expect(array_values($row['hidden']))->toBe([['z'], [], []])
        ->and($row['visible'][0])->toBe(['m:0'])
        ->and($row['visible'][1])->toBe(['n:1', 'z:0:1:slice'])
        ->and($row['visible'][2])->toBe(['w:2']);
});
