<?php

/*
 * Port de packages/preact/tests/seg-placement/kernel-fuzz.test.ts
 * (FullCalendar v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md): pruebas de propiedades
 * con semillas 1..300, el mismo generador pseudoaleatorio y los mismos
 * escenarios que el original (`Fixtures::createRandom`, `buildScenario`).
 *
 * Se porta el primer caso, 'preserves level, tax, order, and coverage
 * invariants when repacking'. No se portan los otros dos:
 *   - 'keeps fully measured pixel layouts in budget and out of the link band'
 *   - 'holds pixel invariants under partial measurement'
 * llaman a `buildPixelLimitedLayout` (modo "auto" por píxeles medidos).
 *
 * Añadidos que no están en el original: determinismo, tope de niveles y que
 * un kernel sin límite no oculta nada.
 */

use KoreUi\Calendar\Layout\Kernel\PlacementKernel;
use KoreUi\Calendar\Layout\Kernel\Slice;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

// pure positioning kernel fuzzing > preserves level, tax, order, and coverage invariants when repacking
it('conserva las invariantes de nivel, tasa, orden y cobertura al reempaquetar (semillas 1-300)', function () {
    for ($seed = 1; $seed <= 300; $seed++) {
        $sources = Fixtures::buildScenario($seed);
        $maxLevels = 1 + $seed % 3;
        $strict = $seed % 2 === 0;
        $slicing = $seed % 3 !== 0;
        $tax = $seed % 2;
        $built = PlacementKernel::buildLevels($sources, $strict, $maxLevels);

        for ($i = 1, $count = count($built->excluded); $i < $count; $i++) {
            Fixtures::invariant(
                $built->excluded[$i - 1]->orderIndex < $built->excluded[$i]->orderIndex,
                'las exclusiones perdieron el orden de las fuentes',
                (string) $seed,
            );
        }

        $placement = PlacementKernel::placeExtras(
            PlacementKernel::wholeSliceLevels($built->levels),
            PlacementKernel::wholeSlices($built->excluded),
            $strict,
            $slicing,
            $tax,
        );
        $label = json_encode(compact('seed', 'maxLevels', 'strict', 'slicing', 'tax'));
        $hiddenGroups = PlacementKernel::groupLaterallyIntersecting($placement->hiddenSlices);

        Fixtures::auditLevels($placement->sliceLevels, $strict, $label);
        Fixtures::auditGroups($hiddenGroups, $label);
        Fixtures::auditCoverage($sources, $placement->sliceLevels, $placement->hiddenSlices, $label);

        if ($tax && $placement->sliceLevels !== []) {
            $bottomLevel = $placement->sliceLevels[count($placement->sliceLevels) - 1];

            foreach ($bottomLevel as $slice) {
                $overlapsLink = false;
                foreach ($hiddenGroups as $group) {
                    $overlapsLink = $overlapsLink || Fixtures::intersects($group, $slice);
                }

                Fixtures::invariant(! $overlapsLink, 'un trozo del último nivel pisa la cobertura del enlace con tasa', $label);
            }
        }
    }
});

// Añadido (no está en el original): el mismo escenario produce siempre el mismo resultado.
it('es determinista: la misma entrada da el mismo resultado (semillas 1-300)', function () {
    $project = function (int $seed): string {
        $sources = Fixtures::buildScenario($seed);
        $built = PlacementKernel::buildLevels($sources, $seed % 2 === 0, 1 + $seed % 3);
        $placement = PlacementKernel::placeExtras(
            PlacementKernel::wholeSliceLevels($built->levels),
            PlacementKernel::wholeSlices($built->excluded),
            $seed % 2 === 0,
            $seed % 3 !== 0,
            $seed % 2,
        );

        return json_encode([
            'levels' => array_map(fn (array $level): array => array_map(fn (Slice $s) => $s->key(), $level), $placement->sliceLevels),
            'hidden' => array_map(fn ($g) => [$g->start, $g->end, array_map(fn (Slice $s) => $s->key(), $g->hiddenSlices)], PlacementKernel::groupLaterallyIntersecting($placement->hiddenSlices)),
            'added' => array_map(fn (Slice $s) => $s->key(), $placement->addedSlices),
        ]);
    };

    for ($seed = 1; $seed <= 300; $seed++) {
        Fixtures::invariant($project($seed) === $project($seed), 'el resultado no es determinista', (string) $seed);
    }
});

// Añadido: el tope de niveles se respeta y, sin tope, nada queda oculto.
it('respeta el tope de niveles y, sin tope, no oculta nada (semillas 1-300)', function () {
    for ($seed = 1; $seed <= 300; $seed++) {
        $sources = Fixtures::buildScenario($seed);
        $strict = $seed % 2 === 0;
        $maxLevels = 1 + $seed % 3;

        $limited = PlacementKernel::buildLevels($sources, $strict, $maxLevels);
        Fixtures::invariant(count($limited->levels) <= $maxLevels, 'más niveles que el tope', (string) $seed);
        Fixtures::invariant(
            count($limited->excluded) + array_sum(array_map('count', $limited->levels)) === count($sources),
            'una fuente se perdió entre niveles y excluidas',
            (string) $seed,
        );

        $unlimited = PlacementKernel::buildLevels($sources, $strict);
        Fixtures::invariant($unlimited->excluded === [], 'sin tope no se excluye nada', (string) $seed);
    }
});
