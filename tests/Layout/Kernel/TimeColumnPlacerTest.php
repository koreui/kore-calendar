<?php

/*
 * Port de packages/preact/tests/seg-placement/timegrid-adapter.test.ts
 * (FullCalendar v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md), con los valores
 * esperados del original, incluidas las regresiones #7914 y #7879.
 *
 * Cambios de forma: FullCalendar pasa a `buildTimeGridSegPlacements` un seg y
 * una geometría vertical en píxeles por separado; aquí es un `Span` cuyo
 * `start`/`end` ya es esa geometría. Los números son los mismos.
 *
 * Al final, la parte de `computeSegHStyle` (TimeGridCol.tsx), que el original
 * no prueba: sus valores están derivados a mano de la regla del CSS.
 */

use KoreUi\Calendar\Layout\Kernel\PlacedEvent;
use KoreUi\Calendar\Layout\Kernel\Span;
use KoreUi\Calendar\Layout\Kernel\TimeColumnPlacement;
use KoreUi\Calendar\Layout\Kernel\TimeColumnPlacer;
use PHPUnit\Framework\Assert;

/** `buildFixture()`: spans `[id, start, end]` en el orden dado. */
function timeSpans(array $ranges): array
{
    return array_map(fn (array $r): Span => new Span($r[0], $r[1], $r[2]), $ranges);
}

/** @return array<string, PlacedEvent> `placementById()` */
function timePlacementById(TimeColumnPlacement $result): array
{
    $byId = [];
    foreach ($result->placed as $placement) {
        $byId[$placement->span->key] = $placement;
    }

    return $byId;
}

/** @return list<array{0: string, 1: float, 2: float}> `projectPlacements()` */
function timeProject(TimeColumnPlacement $result): array
{
    return array_map(fn (PlacedEvent $p): array => [$p->span->key, $p->levelCoord, $p->thickness], $result->placed);
}

/**
 * `auditVisibleRectangles()`: coordenada y grosor válidos, dentro de la columna
 * (con el épsilon del original) y, si dos eventos se solapan en el tiempo,
 * separados en horizontal.
 */
function timeAuditRectangles(TimeColumnPlacement $result, string $label): void
{
    $eps = PHP_FLOAT_EPSILON * 8;

    foreach ($result->placed as $p) {
        Assert::assertTrue(is_finite($p->levelCoord), $label);
        Assert::assertTrue(is_finite($p->thickness), $label);
        Assert::assertGreaterThanOrEqual(0, $p->levelCoord, $label);
        Assert::assertGreaterThan(0, $p->thickness, $label);
        Assert::assertLessThanOrEqual(1 + $eps, $p->levelCoord + $p->thickness, $label);
    }

    $count = count($result->placed);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $left = $result->placed[$i];
            $right = $result->placed[$j];

            if (! ($left->span->start < $right->span->end && $right->span->start < $left->span->end)) {
                continue;
            }

            $separated = $left->levelCoord + $left->thickness <= $right->levelCoord + $eps
                || $right->levelCoord + $right->thickness <= $left->levelCoord + $eps;

            Assert::assertTrue($separated, "{$label}: {$left->span->key} y {$right->span->key}");
        }
    }
}

// TimeGrid production placement adapter > projects ordinary overlap into sensible normalized rectangles
it('proyecta un solape corriente en rectángulos normalizados razonables', function () {
    $spans = timeSpans([['a', 0, 100], ['b', 20, 80], ['c', 100, 120]]);
    $result = TimeColumnPlacer::place($spans);

    expect($result->placed)->toHaveCount(3)
        ->and($result->hidden)->toBe([])
        ->and(timeProject($result))->toEqual([
            ['a', 0, 0.5],
            ['b', 0.5, 0.5],
            ['c', 0, 1],
        ])
        // El span viaja intacto (`seg.eventRange` toBe, `segVertical` toBe).
        ->and($result->placed[0]->span)->toBe($spans[0]);

    timeAuditRectangles($result, 'ordinary overlap');
});

// TimeGrid production placement adapter > preserves resolved order geometrically when eventOrderStrict is true
it('conserva el orden resuelto en la geometría con eventOrderStrict', function () {
    $result = TimeColumnPlacer::place(timeSpans([['first', 0, 50], ['second', 0, 100], ['third', 50, 100]]), strict: true);
    $byId = timePlacementById($result);

    expect($byId['first']->levelCoord)->toBeLessThan($byId['second']->levelCoord)
        ->and($byId['second']->levelCoord)->toBeLessThan($byId['third']->levelCoord)
        ->and(array_map(fn (PlacedEvent $p) => $p->span->key, $result->placed))->toBe(['first', 'second', 'third'])
        ->and($byId['first']->levelCoord)->toEqualWithDelta(0, 1e-9)
        ->and($byId['first']->thickness)->toEqualWithDelta(1 / 3, 1e-9)
        ->and($byId['second']->levelCoord)->toEqualWithDelta(1 / 3, 1e-9)
        ->and($byId['second']->thickness)->toEqualWithDelta(1 / 3, 1e-9)
        ->and($byId['third']->levelCoord)->toEqualWithDelta(2 / 3, 1e-9)
        ->and($byId['third']->thickness)->toEqualWithDelta(1 / 3, 1e-9);

    timeAuditRectangles($result, 'strict order');
});

// TimeGrid production placement adapter > limits eventMaxStack and forms hidden overflow groups
it('limita eventMaxStack y forma grupos ocultos de desbordamiento', function () {
    $spans = timeSpans([['visible-a', 0, 100], ['visible-b', 0, 100], ['hidden', 0, 100]]);
    $result = TimeColumnPlacer::place($spans, maxStack: 2);

    expect(array_map(fn (PlacedEvent $p) => $p->span->key, $result->placed))->toBe(['visible-a', 'visible-b'])
        ->and($result->hidden)->toHaveCount(1)
        ->and([$result->hidden[0]->start, $result->hidden[0]->end])->toBe([0, 100])
        ->and($result->hidden[0]->spans)->toBe([$spans[2]]);

    timeAuditRectangles($result, 'max stack');
});

// TimeGrid production placement adapter > maps eventMaxStack overflow through transitive hidden globbing
it('lleva el desbordamiento de eventMaxStack por la unión transitiva de ocultos', function () {
    $result = TimeColumnPlacer::place(timeSpans([
        ['visible', 0, 100],
        ['hidden-late', 40, 70],
        ['hidden-early', 10, 50],
        ['hidden-separate', 80, 90],
    ]), maxStack: 1);

    expect(array_map(fn (PlacedEvent $p) => $p->span->key, $result->placed))->toBe(['visible'])
        ->and(array_map(fn ($g) => [
            'span' => [$g->start, $g->end],
            'segs' => array_map(fn (Span $s) => $s->key, $g->spans),
        ], $result->hidden))->toBe([
            ['span' => [10, 70], 'segs' => ['hidden-late', 'hidden-early']],
            ['span' => [80, 90], 'segs' => ['hidden-separate']],
        ]);
});

// TimeGrid production placement adapter > treats exact adjacency as non-overlap in levels and hidden groups
it('trata la adyacencia exacta como no solape en los niveles y en los grupos ocultos', function () {
    $spans = timeSpans([['before', 0, 50], ['after', 50, 100]]);
    $visible = TimeColumnPlacer::place($spans);
    $hidden = TimeColumnPlacer::place($spans, maxStack: 0);

    expect(timeProject($visible))->toEqual([
        ['before', 0, 1],
        ['after', 0, 1],
    ])->and(array_map(fn ($g) => [$g->start, $g->end], $hidden->hidden))->toBe([
        [0, 50],
        [50, 100],
    ]);
});

// TimeGrid production placement adapter > projects stable pixel-union hidden groups with original segs in resolved order
it('proyecta grupos ocultos estables con los spans originales en orden resuelto', function () {
    $spans = timeSpans([
        ['blocker', 0, 100],
        ['later-start-priority', 40, 90],
        ['earlier-start-later-priority', 20, 60],
    ]);
    $first = TimeColumnPlacer::place($spans, maxStack: 1);
    $resized = TimeColumnPlacer::place(
        array_map(fn (Span $s) => new Span($s->key, $s->start * 2, $s->end * 2), $spans),
        maxStack: 1,
    );

    expect($first->hidden)->toHaveCount(1)
        ->and([$first->hidden[0]->start, $first->hidden[0]->end])->toBe([20, 90])
        ->and($first->hidden[0]->spans)->toBe([$spans[1], $spans[2]])
        // La clave sale de los datos del evento oculto, no de la geometría: reescalar
        // la rejilla (todas las coordenadas) no vuelve a montar el popover.
        ->and($resized->hidden[0]->key)->toBe($first->hidden[0]->key)
        ->and($first->hidden[0]->key)->toBe('later-start-priority');
});

// TimeGrid production placement adapter > excludes segs without vertical geometry without throwing
// (adaptado: en PHP no hay spans sin geometría; el caso equivalente es una columna vacía)
it('devuelve una columna vacía sin lanzar cuando no hay spans', function () {
    $result = TimeColumnPlacer::place([]);

    expect($result->placed)->toBe([])
        ->and($result->hidden)->toBe([]);
});

// TimeGrid production placement adapter > emits temporal-start DOM order independently of placement priority
it('emite el orden del DOM por inicio temporal con independencia de la prioridad', function () {
    $result = TimeColumnPlacer::place(timeSpans([['priority-first', 20, 80], ['temporal-first', 0, 50]]));
    $byId = timePlacementById($result);

    expect(array_map(fn (PlacedEvent $p) => $p->span->key, $result->placed))->toBe(['temporal-first', 'priority-first'])
        ->and($byId['priority-first']->levelCoord)->toBe(0.0)
        ->and($byId['temporal-first']->levelCoord)->toBe(0.5);
});

// TimeGrid production placement adapter > covers https://github.com/fullcalendar/fullcalendar/issues/7914 without forbidden overlap
it('#7914: no deja solapes prohibidos con 32 eventos, orden propio y orden estricto', function () {
    // Geometría exacta y orden personalizado resuelto de la incidencia #7914: [id, inicio, fin, orden].
    $ranges = [
        ['0', 14, 14.5, '030'], ['1', 14.5, 15, '030'], ['2', 15, 16, '010'], ['3', 16, 16.5, '030'],
        ['4', 16.5, 17, '030'], ['5', 17, 17.5, '030'], ['6', 18, 19, '020'], ['7', 10, 12, '095'],
        ['8', 12, 14, '095'], ['9', 14.5, 16.5, '085'], ['10', 16.5, 18.5, '085'], ['11', 16.5, 18.5, '095'],
        ['12', 10, 11, '030'], ['13', 11, 12, '110'], ['14', 14, 14.5, '040'], ['15', 16, 17, '010'],
        ['16', 17, 17.5, '030'], ['17', 17.5, 18, '030'], ['18', 11, 12, '110'], ['19', 17.5, 18, '030'],
        ['20', 13.5, 14, '040'], ['21', 18, 19, '010'], ['22', 12.5, 14, '115'], ['23', 14, 15.5, '115'],
        ['24', 14.5, 16.5, '115'], ['25', 15.5, 16, '110'], ['26', 10, 11, '010'], ['27', 13.5, 14, '110'],
        ['28', 18.5, 20.5, '105'], ['29', 14, 15, '040'], ['30', 16, 17, '040'], ['31', 15.5, 17, '075'],
    ];
    // `localeCompare` sobre cadenas de dígitos del mismo largo equivale a strcmp; el orden es estable.
    usort($ranges, fn (array $l, array $r): int => strcmp($l[3], $r[3]));

    $spans = timeSpans(array_map(fn (array $r): array => [$r[0], $r[1], $r[2]], $ranges));
    $result = TimeColumnPlacer::place($spans, strict: true);

    expect($result->placed)->toHaveCount(count($spans))
        ->and($result->hidden)->toBe([]);

    timeAuditRectangles($result, 'https://github.com/fullcalendar/fullcalendar/issues/7914');
});

// TimeGrid production placement adapter > covers https://github.com/fullcalendar/fullcalendar/issues/7879 without a zero-width event
it('#7879: ningún evento queda con ancho cero con 19 eventos', function () {
    // Geometría exacta recortada al día y orden por defecto (inicio, duración) de la incidencia #7879.
    $ranges = [
        ['86987', 10, 13], ['86988', 0, 8], ['86989', 0, 8], ['86990', 8, 22], ['86991', 6, 8],
        ['86992', 8, 16.5], ['86993', 8, 13], ['86994', 8, 12], ['86995', 8.5, 9.5], ['86996', 8.5, 8.75],
        ['86997', 11.5, 14.5], ['86998', 13, 17], ['86999', 17, 23], ['87000', 18, 23], ['87001', 14, 24],
        ['87002', 14, 22], ['87003', 16, 23], ['87004', 15.5, 23.5], ['87005', 18, 23],
    ];
    usort($ranges, fn (array $l, array $r): int => ($l[1] <=> $r[1])
        ?: (($r[2] - $r[1]) <=> ($l[2] - $l[1])));

    $result = TimeColumnPlacer::place(timeSpans($ranges));

    expect($result->placed)->toHaveCount(count($ranges))
        ->and($result->hidden)->toBe([])
        ->and(timePlacementById($result)['86987']->thickness)->toBeGreaterThan(0);

    timeAuditRectangles($result, 'https://github.com/fullcalendar/fullcalendar/issues/7879');
});

// --- computeSegHStyle (TimeGridCol.tsx): valores derivados a mano, no del original -------------------

it('con slotEventOverlap el ancho se dobla sin pasar del borde, con z-index y margen final', function () {
    $result = TimeColumnPlacer::place(timeSpans([['a', 0, 100], ['b', 20, 80], ['c', 100, 120]]));
    [$a, $b, $c] = $result->placed;

    // a: coord 0, grosor .5 -> lejano min(1, 0 + .5 * 2) = 1 -> left 0, right 0. Lo pisa b (forwardDepth 1).
    expect([$a->left, $a->right, $a->zIndex, $a->marginEnd])->toBe([0.0, 0.0, 1, true])
        // b: coord .5, grosor .5 -> lejano min(1, .5 + 1) = 1 -> right 0. z-index 2, nada lo pisa.
        ->and([$b->left, $b->right, $b->zIndex, $b->marginEnd])->toBe([0.5, 0.0, 2, false])
        ->and([$c->left, $c->right, $c->zIndex, $c->marginEnd])->toBe([0.0, 0.0, 1, false]);
});

it('sin slotEventOverlap el rectángulo es el base y no hay margen final', function () {
    $result = TimeColumnPlacer::place(timeSpans([['a', 0, 100], ['b', 20, 80]]), slotEventOverlap: false);
    [$a, $b] = $result->placed;

    expect([$a->left, $a->right, $a->marginEnd])->toBe([0.0, 0.5, false])
        ->and([$b->left, $b->right, $b->marginEnd])->toBe([0.5, 0.0, false])
        // El z-index no depende de slotEventOverlap.
        ->and([$a->zIndex, $b->zIndex])->toBe([1, 2]);
});

it('con slotEventOverlap el doble ancho no pasa del borde derecho en una cadena de tres', function () {
    // a[0,40) b[30,70) c[60,100): a-b y b-c chocan, a-c no. Dos niveles base en una sola componente.
    $result = TimeColumnPlacer::place(timeSpans([['a', 0, 40], ['b', 30, 70], ['c', 60, 100]]));
    $byId = timePlacementById($result);

    // a y c comparten nivel 0 y miden 1/2 hasta el colisionador (b, nivel 1): b mide 1/2 hasta el borde.
    expect([$byId['a']->levelCoord, $byId['a']->thickness])->toBe([0.0, 0.5])
        ->and([$byId['b']->levelCoord, $byId['b']->thickness])->toBe([0.5, 0.5])
        ->and([$byId['c']->levelCoord, $byId['c']->thickness])->toBe([0.0, 0.5])
        // Doble ancho: a y c llegan al borde; b ya estaba en el borde.
        ->and([$byId['a']->right, $byId['b']->right, $byId['c']->right])->toBe([0.0, 0.0, 0.0])
        // b está por encima de a y de c; las dos cadenas son de profundidad 1.
        ->and([$byId['a']->zIndex, $byId['b']->zIndex, $byId['c']->zIndex])->toBe([1, 2, 1]);
});

it('un evento de duración cero (altura mínima ya aplicada por el llamador) también se coloca', function () {
    $result = TimeColumnPlacer::place(timeSpans([['a', 10, 10], ['b', 0, 100]]));

    expect($result->placed)->toHaveCount(2)
        ->and($result->hidden)->toBe([]);
});
