<?php

/*
 * Port de packages/preact/tests/seg-placement/kernel-correctness.test.ts
 * (FullCalendar v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md), con los valores
 * esperados del original.
 *
 * No se portan los 4 casos que llaman a `buildPixelLimitedLayout`: es el modo
 * "auto" por píxeles, que mide alturas en el navegador y no existe en el
 * servidor (docs/calendar.md §7.2). Son:
 *   - 'accepts a fully measured, in-band pixel candidate wholesale'
 *   - 'evicts whole slices intruding into the link band via the safe closure'
 *   - 'mounts candidate partials while the deepest occupant pays the link tax'
 *   - 'keeps unmeasured frontier wholes as donors outside the hidden stream'
 */

use KoreUi\Calendar\Layout\Kernel\PlacementKernel;
use KoreUi\Calendar\Layout\Kernel\Slice;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

// pure positioning kernel > puts wider same-start slices of one event first
it('pone primero los trozos más anchos de un evento que empiezan en el mismo sitio', function () {
    [$source] = Fixtures::segs([['event', 0, 3]]);
    $whole = Slice::whole($source);
    $narrower = new Slice($source, 0, 1, true, false);

    expect(Fixtures::projectSlices(PlacementKernel::sortByEventOrder([$narrower, $whole])))->toEqual([
        ['event', 0, 3],
        ['event', 0, 1],
    ]);
});

// pure positioning kernel > preserves rejection order and makes strict order part of topology
it('conserva el orden de rechazo y hace del orden estricto parte de la topología', function () {
    $rejectedScenario = Fixtures::segs([
        ['first', 0, 3],
        ['second', 0, 1],
        ['third', 1, 2],
        ['fourth', 0, 3],
    ]);
    $limited = PlacementKernel::buildLevels($rejectedScenario, false, 1);

    expect(array_map(fn ($s) => $s->key, $limited->excluded))->toEqual([
        'second:0',
        'third:1',
        'fourth:0',
    ]);

    $topologyScenario = Fixtures::segs([
        ['a', 0, 1],
        ['b', 0, 2],
        ['c', 1, 2],
    ]);

    expect(Fixtures::projectSegLevels(PlacementKernel::buildLevels($topologyScenario, false)->levels))
        ->toEqual([['a', 'c'], ['b']])
        ->and(Fixtures::projectSegLevels(PlacementKernel::buildLevels($topologyScenario, true)->levels))
        ->toEqual([['a'], ['b'], ['c']]);
});

// pure positioning kernel > places the best scored slice plan and hides the remainder
it('coloca el plan de cortes mejor puntuado y oculta el resto', function () {
    [$base, $extra] = Fixtures::segs([
        ['base', 1, 2],
        ['extra', 0, 3],
    ]);

    $unsliced = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$base]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        false,
        0,
    );

    expect(Fixtures::projectSlices(Fixtures::flat($unsliced->sliceLevels)))->toEqual([
        ['base', 1, 2],
    ])->and(Fixtures::projectSlices(Fixtures::hiddenGroupsOf($unsliced)[0]->hiddenSlices))->toEqual([
        ['extra', 0, 3],
    ]);

    $sliced = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$base]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        true,
        0,
    );

    expect(Fixtures::projectSlices(Fixtures::flat($sliced->sliceLevels)))->toEqual([
        ['extra', 0, 1],
        ['base', 1, 2],
        ['extra', 2, 3],
    ])->and(Fixtures::projectSlices(Fixtures::hiddenGroupsOf($sliced)[0]->hiddenSlices))->toEqual([
        ['extra', 1, 2],
    ])->and(Fixtures::projectSlices($sliced->addedSlices))->toEqual([
        ['extra', 0, 1],
        ['extra', 2, 3],
    ]);

    Fixtures::auditCoverage([$base, $extra], $sliced->sliceLevels, $sliced->hiddenSlices);
});

// pure positioning kernel > reserves the bottom level only under a level tax
it('reserva el último nivel solo bajo una tasa de nivel', function () {
    [$base, $extra] = Fixtures::segs([
        ['base', 0, 1],
        ['extra', 0, 1],
    ]);

    $untaxed = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$base]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        false,
        0,
    );

    expect(Fixtures::projectSlices(Fixtures::flat($untaxed->sliceLevels)))->toEqual([['base', 0, 1]])
        ->and(Fixtures::projectSlices(Fixtures::hiddenGroupsOf($untaxed)[0]->hiddenSlices))->toEqual([
            ['extra', 0, 1],
        ]);

    $taxed = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$base]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        false,
        1,
    );
    $group = Fixtures::hiddenGroupsOf($taxed)[0];

    expect(Fixtures::flat($taxed->sliceLevels))->toBe([])
        ->and([$group->start, $group->end])->toEqual([0, 1])
        // Los miembros del grupo van en orden de evento, no en el orden en que se ocultaron.
        ->and(array_map(fn (Slice $s) => $s->source->payload, $group->hiddenSlices))->toEqual(['base', 'extra']);

    Fixtures::auditCoverage([$base, $extra], $taxed->sliceLevels, $taxed->hiddenSlices);
});

// pure positioning kernel > makes bounded coordinate exclusion final and non-blocking
it('hace definitiva y no bloqueante la exclusión por presupuesto de coordenadas', function () {
    [$tooTall, $later] = Fixtures::segs([
        ['too-tall', 0, 1],
        ['later', 0, 1],
    ]);
    $levels = PlacementKernel::wholeSliceLevels([[$tooTall], [$later]]);
    $result = PlacementKernel::resolveLevelCoords($levels, [
        $tooTall->key => 12,
        $later->key => 5,
    ], 10);

    // La estructura de colocación se reordena alrededor de la exclusión.
    expect(array_map(Fixtures::projectSlices(...), $result->placementSliceLevels))->toEqual([
        [['later', 0, 1]],
    ])
        ->and($result->excludedSlices)->toEqual([$levels[0][0]])
        ->and($result->sliceCoords[$later->key])->toBe(0);
});

// pure positioning kernel > keeps unmeasured slices pending and non-blocking
it('deja pendientes y sin bloquear los trozos sin medir', function () {
    [$pending, $measured] = Fixtures::segs([
        ['pending', 0, 1],
        ['measured', 0, 1],
    ]);
    $levels = PlacementKernel::wholeSliceLevels([[$pending], [$measured]]);
    $result = PlacementKernel::resolveLevelCoords($levels, [$measured->key => 5], 10);

    expect($result->isSettled)->toBeFalse()
        ->and($result->excludedSlices)->toEqual([])
        // La estructura de colocación se reordena alrededor del pendiente.
        ->and(array_map(Fixtures::projectSlices(...), $result->placementSliceLevels))->toEqual([
            [['measured', 0, 1]],
        ])
        ->and($result->sliceCoords[$measured->key])->toBe(0);
});

// pure positioning kernel > uses coordinate tolerance without relaxing lateral intersections
it('usa tolerancia en las coordenadas sin relajar las intersecciones laterales', function () {
    [$source] = Fixtures::segs([['source', 0, 2]]);
    $whole = PlacementKernel::wholeSlices([$source])[0];
    $slightlyOver = 10 + PlacementKernel::GEOMETRY_TOLERANCE / 2;
    $resolved = PlacementKernel::resolveLevelCoords([[$whole]], [$whole->key() => $slightlyOver], 10);

    expect($resolved->placementSliceLevels[0])->toEqual([$whole]);

    // Dos mitades exactamente adyacentes no se agrupan.
    expect(PlacementKernel::groupLaterallyIntersecting([
        new Slice($source, 0, 1, true, false),
        new Slice($source, 1, 2, false, true),
    ]))->toHaveCount(2);
});

// pure positioning kernel > merges hull groups per source event in event order
it('funde los grupos en el casco de cada evento fuente, en orden de evento', function () {
    [$first, $second, $bridge] = PlacementKernel::wholeSlices(Fixtures::segs([
        ['first', 0, 1],
        ['second', 2, 3],
        ['bridge', 0.5, 2.5],
    ]));
    $groups = PlacementKernel::groupLaterallyIntersecting([$first, $second, $bridge]);

    expect($groups)->toHaveCount(1)
        ->and([$groups[0]->start, $groups[0]->end])->toEqual([0, 3])
        ->and($groups[0]->key)->toBe($first->key())
        ->and(array_map(fn (Slice $s) => $s->source->payload, $groups[0]->hiddenSlices))
        ->toEqual(['first', 'second', 'bridge']);

    // Los fragmentos desconectados de una fuente quedan en grupos distintos: la
    // adyacencia exacta nunca fusiona.
    [$wide, $bridger] = Fixtures::segs([
        ['wide', 0, 4],
        ['bridger', 0.5, 2.5],
    ]);

    expect(PlacementKernel::groupLaterallyIntersecting([
        new Slice($wide, 0, 1, true, false),
        new Slice($wide, 3, 4, false, true),
    ]))->toHaveCount(2);

    // Cuando otro trozo conecta dos fragmentos de una fuente en un grupo, esas
    // entradas se funden en su casco lateral, con la continuidad de inicio y fin
    // derivada de los bordes reales de la fuente.
    $bridged = PlacementKernel::groupLaterallyIntersecting([
        new Slice($wide, 0, 1, true, false),
        new Slice($wide, 2, 3, false, false),
        Slice::whole($bridger),
    ]);

    expect($bridged)->toHaveCount(1)
        ->and(Fixtures::projectSlices($bridged[0]->hiddenSlices))->toEqual([
            ['wide', 0, 3],
            ['bridger', 0.5, 2.5],
        ])
        ->and([$bridged[0]->hiddenSlices[0]->isStart, $bridged[0]->hiddenSlices[0]->isEnd])->toBe([true, false]);
});

// pure positioning kernel > widens taxed link coverage through bottom-level consumption
it('amplía la cobertura del enlace con tasa al consumir el último nivel', function () {
    [$base, $extra] = Fixtures::segs([
        ['base', 0, 3],
        ['extra', 1, 2],
    ]);
    $placement = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$base]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        false,
        1,
    );
    $group = Fixtures::hiddenGroupsOf($placement)[0];

    expect([$group->start, $group->end])->toEqual([0, 3])
        ->and(array_map(fn (Slice $s) => $s->source->payload, $group->hiddenSlices))->toEqual(['base', 'extra']);

    Fixtures::auditCoverage([$base, $extra], $placement->sliceLevels, $placement->hiddenSlices);
});

// pure positioning kernel > derives whole keys from the source and partial keys from the slice start
it('deriva las claves enteras de la fuente y las parciales del inicio del trozo', function () {
    [$source] = Fixtures::segs([['event', 2, 5]]);
    $whole = Slice::whole($source);
    $shifted = new Slice($source, 3, 5, false, true);
    $narrowed = new Slice($source, 2, 4, true, false);

    expect($whole->key())->toBe('event:2')
        ->and($shifted->key())->toBe('event:2:3:slice')
        // Recortar solo el final conserva la clave, y con ella el nodo del DOM.
        ->and($narrowed->key())->toBe('event:2:2:slice')
        ->and(PlacementKernel::sliceKey($whole))->toBe('event:2')
        ->and(PlacementKernel::isPartialSlice($narrowed))->toBeTrue()
        ->and(PlacementKernel::isPartialSlice($whole))->toBeFalse();
});

// pure positioning kernel > respects strict event order when repacking extras
it('respeta el orden de evento estricto al reempaquetar los extras', function () {
    // `late` (orden 2) no puede estar por encima de `early` (orden 0) donde se intersecan.
    [$early, $unrelated, $late] = Fixtures::segs([
        ['early', 0, 2],
        ['unrelated', 2, 3],
        ['late', 0, 2],
    ]);

    $loose = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$unrelated], [$early]]),
        PlacementKernel::wholeSlices([$late]),
        false,
        false,
        0,
    );

    expect(Fixtures::projectSlices($loose->sliceLevels[0]))->toEqual([
        ['late', 0, 2],
        ['unrelated', 2, 3],
    ]);

    $strict = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$unrelated], [$early]]),
        PlacementKernel::wholeSlices([$late]),
        true,
        false,
        0,
    );

    expect(Fixtures::projectSlices($strict->sliceLevels[0]))->toEqual([
        ['unrelated', 2, 3],
    ])->and(Fixtures::projectSlices(Fixtures::hiddenGroupsOf($strict)[0]->hiddenSlices))->toEqual([
        ['late', 0, 2],
    ]);
});

// --- Derivados de la spec (docs/calendar.md §7.1), no del original -----------------------------
// `puntuación = visible/total − 0.15·(tramos−1)` y planes de hasta 3 tramos. Los casos del
// original no distinguen la penalización ni el tope: aquí se eligen para que sí lo hagan.

it('la penalización de 0.15 por tramo extra prefiere un solo tramo largo a dos que exponen algo más', function () {
    // El nivel 0 deja libres [0,6) y [7,8) de `extra[0,10)`.
    // Un tramo: 6/10 = 0.60. Dos tramos: 7/10 − 0.15 = 0.55. Sin penalización ganarían los dos.
    [$b1, $b2, $extra] = Fixtures::segs([
        ['b1', 6, 7],
        ['b2', 8, 10],
        ['extra', 0, 10],
    ]);
    $placement = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$b1, $b2]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        true,
        0,
    );
    $visibleExtra = array_values(array_filter(
        Fixtures::projectSlices(Fixtures::flat($placement->sliceLevels)),
        fn (array $p) => $p[0] === 'extra',
    ));

    expect($visibleExtra)->toEqual([['extra', 0, 6]])
        ->and(Fixtures::projectSlices($placement->hiddenSlices))->toEqual([['extra', 6, 10]]);

    Fixtures::auditCoverage([$b1, $b2, $extra], $placement->sliceLevels, $placement->hiddenSlices);
});

it('un plan de cortes tiene como máximo 3 tramos', function () {
    // Cuatro tramos libres de 24 a 25. Con 4 tramos se expondría 97/100 − 0.45 = 0.52, mejor que
    // los 3 tramos que dan 73/100 − 0.30 = 0.43; el tope de 3 obliga a dejar uno oculto.
    [$b1, $b2, $b3, $extra] = Fixtures::segs([
        ['b1', 24, 25],
        ['b2', 49, 50],
        ['b3', 74, 75],
        ['extra', 0, 100],
    ]);
    $placement = PlacementKernel::placeExtras(
        PlacementKernel::wholeSliceLevels([[$b1, $b2, $b3]]),
        PlacementKernel::wholeSlices([$extra]),
        false,
        true,
        0,
    );
    $visibleExtra = array_values(array_filter(
        Fixtures::projectSlices(Fixtures::flat($placement->sliceLevels)),
        fn (array $p) => $p[0] === 'extra',
    ));

    // Los tres tramos más largos (el de 25 y, a igual largo, los primeros por inicio).
    expect($visibleExtra)->toEqual([['extra', 0, 24], ['extra', 25, 49], ['extra', 75, 100]]);

    Fixtures::auditCoverage([$b1, $b2, $b3, $extra], $placement->sliceLevels, $placement->hiddenSlices);
});
