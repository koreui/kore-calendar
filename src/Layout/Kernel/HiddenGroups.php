<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * El acumulador de trozos ocultos y su compilación final en grupos "+N más".
 *
 * Port de `addHiddenSliceToGroups`, `finalizeHiddenGroups` y
 * `mergeAdjacentSlices` de `seg-placement/kernel.ts` (FullCalendar v7.1.3, MIT).
 *
 * @internal
 */
final class HiddenGroups
{
    private function __construct() {}

    /**
     * Añade un trozo oculto a su componente de intersección estricta y devuelve
     * SOLO los tramos que pasan a estar cubiertos por primera vez.
     *
     * Dos grupos exactamente adyacentes se quedan separados a propósito: cada
     * grupo es un enlace "+N más" que se pinta por su cuenta. La lista de grupos
     * se mantiene ordenada por inicio.
     *
     * Devolver solo lo nuevo es lo que hace que la "tasa" del enlace se cobre una
     * vez por tramo de cobertura y no una vez por cada evento oculto.
     *
     * Los trozos de un grupo NO se mantienen ordenados: `finalize()` los ordena una
     * vez por grupo. Reordenarlos en cada alta costaba O(n log n) por trozo oculto y
     * en un mes con miles de eventos y `maxEvents` era el grueso del layout; nada
     * lee el orden de un grupo hasta que se compila.
     *
     * @param  list<RawHiddenGroup>  $groups
     * @return list<Lateral>
     */
    public static function add(array &$groups, Slice $slice): array
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

        SpanMath::insertLaterally($untouched, new RawHiddenGroup($start, $end, $merged));
        $groups = $untouched;

        return $newSpans;
    }

    /**
     * Compila los grupos internos para el consumidor: una entrada por evento
     * oculto, en orden de evento, abarcando el casco de sus fragmentos.
     *
     * @param  list<RawHiddenGroup>  $groups
     * @return list<HiddenSliceGroup>
     */
    public static function finalize(array $groups): array
    {
        return array_map(function (RawHiddenGroup $group): HiddenSliceGroup {
            $hiddenSlices = self::mergeAdjacent(PlacementKernel::sortByEventOrder($group->hiddenSlices));

            return new HiddenSliceGroup(
                $hiddenSlices[0]->key(),
                $group->start,
                $group->end,
                $hiddenSlices,
            );
        }, $groups);
    }

    /**
     * Funde las rachas de una misma fuente en una lista ordenada por evento en un
     * solo trozo que abarca el casco lateral de la racha.
     *
     * El casco puede cubrir territorio donde la fuente sí es visible: el
     * consumidor deduce `isStart`/`isEnd` de los bordes ocultos exteriores, no
     * de la cobertura oculta exacta.
     *
     * @param  list<Slice>  $slices
     * @return list<Slice>
     */
    private static function mergeAdjacent(array $slices): array
    {
        $merged = [];

        foreach ($slices as $slice) {
            $last = count($merged) - 1;

            if ($last >= 0 && $merged[$last]->source === $slice->source) {
                $previous = $merged[$last];
                $merged[$last] = Slice::whole($previous->source)->narrower(
                    $previous->start,
                    max($previous->end, $slice->end),
                );
            } else {
                $merged[] = $slice;
            }
        }

        return $merged;
    }
}
