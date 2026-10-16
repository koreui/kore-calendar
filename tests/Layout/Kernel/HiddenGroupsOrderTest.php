<?php

/*
 * `HiddenGroups::add()` ya no reordena los trozos del grupo en cada alta: los ordena
 * `finalize()` una sola vez. Este test compara la salida con la versión de antes
 * (ordenar en cada alta) sobre trozos aleatorios, incluidos varios fragmentos de una
 * misma fuente, que son los que `mergeAdjacent()` funde.
 */

use KoreUi\Calendar\Layout\Kernel\HiddenGroups;
use KoreUi\Calendar\Layout\Kernel\HiddenSliceGroup;
use KoreUi\Calendar\Layout\Kernel\PlacementKernel;
use KoreUi\Calendar\Layout\Kernel\RawHiddenGroup;
use KoreUi\Calendar\Layout\Kernel\Slice;
use KoreUi\Calendar\Layout\Kernel\Source;
use KoreUi\Calendar\Layout\Kernel\SpanMath;

/** `HiddenGroups::add()` tal como era: reordena los trozos de la unión en cada alta. */
function hiddenGroupsReferenceAdd(array &$groups, Slice $slice): array
{
    $newSpans = SpanMath::subtractCovered($slice, $groups);
    $untouched = [];
    $merged = [$slice];
    $start = $slice->start;
    $end = $slice->end;

    foreach ($groups as $group) {
        if (SpanMath::intersect($group, $slice) !== null) {
            array_push($merged, ...$group->hiddenSlices);
            $start = min($start, $group->start);
            $end = max($end, $group->end);
        } else {
            $untouched[] = $group;
        }
    }

    usort($merged, PlacementKernel::compareByEventOrder(...));
    SpanMath::insertLaterally($untouched, new RawHiddenGroup($start, $end, $merged));
    $groups = $untouched;

    return $newSpans;
}

/** @param list<HiddenSliceGroup> $groups */
function hiddenGroupsShape(array $groups): array
{
    return array_map(fn (HiddenSliceGroup $g) => [
        $g->key, $g->start, $g->end,
        array_map(fn (Slice $s) => [$s->source->key, $s->start, $s->end, $s->isStart, $s->isEnd], $g->hiddenSlices),
    ], $groups);
}

it('ordenar al compilar da los mismos grupos que ordenar en cada alta (semillas 1-200)', function () {
    for ($seed = 1; $seed <= 200; $seed++) {
        mt_srand($seed);
        $slices = [];
        $sources = mt_rand(2, 25);

        for ($o = 0; $o < $sources; $o++) {
            $start = mt_rand(0, 12);
            $end = $start + mt_rand(1, 6);
            $source = new Source("e{$o}:{$start}", $start, $end, true, true, $o);
            $whole = Slice::whole($source);

            // La mitad de las fuentes llegan partidas en dos fragmentos, como cuando el kernel corta un evento que cruza un hueco.
            if ($end - $start >= 3 && mt_rand(0, 1)) {
                $cut = $start + 1;
                $slices[] = $whole->narrower($start, $cut);
                $slices[] = $whole->narrower($cut + 1, $end);
            } else {
                $slices[] = $whole;
            }
        }

        // El kernel los añade en un orden que no es el de evento.
        shuffle($slices);

        $expected = [];
        $actual = [];
        $spansExpected = [];
        $spansActual = [];

        foreach ($slices as $slice) {
            $spansExpected[] = hiddenGroupsReferenceAdd($expected, $slice);
            $spansActual[] = HiddenGroups::add($actual, $slice);
        }

        $label = "semilla {$seed}";

        expect(hiddenGroupsShape(HiddenGroups::finalize($actual)))
            ->toEqual(hiddenGroupsShape(HiddenGroups::finalize($expected)), $label);

        // Lo que devuelve add() (los tramos nuevos de cobertura) no depende del orden de los trozos del grupo.
        expect(array_map(fn (array $spans) => array_map(fn ($s) => [$s->start, $s->end], $spans), $spansActual))
            ->toEqual(array_map(fn (array $spans) => array_map(fn ($s) => [$s->start, $s->end], $spans), $spansExpected), $label);
    }
});
