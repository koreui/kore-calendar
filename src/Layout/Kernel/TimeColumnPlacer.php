<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Adaptador de la rejilla horaria: reparte los eventos de UNA columna (un día,
 * o un recurso de un día) en sentido horizontal.
 *
 * Port de `packages/preact/src/timegrid/seg-placement-adapter.ts`
 * (`layoutTimeGridColumnByMaxLevel`, `positionTimeGridPlacements`) y de
 * `computeSegHStyle` de `timegrid/components/TimeGridCol.tsx` (FullCalendar
 * v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md).
 *
 * Usa el MISMO kernel que la vista mes, girado: el eje lateral es el tiempo y
 * los niveles son las columnas dentro del día. Como el kernel solo compara
 * spans entre sí y nunca con la longitud del eje, escalar todas las coordenadas
 * (píxeles, minutos, segundos) no cambia niveles ni colisiones: por eso basta
 * con pasar números. Lo único que depende de la unidad es la altura mínima del
 * evento (`eventMinHeight`), que el llamador aplica ANTES, porque cambia qué
 * eventos colisionan (docs/calendar.md §7.3, FullCalendar #961).
 *
 * Sin `tax` ni `slicing` aquí: un enlace "+N más" de la rejilla horaria es una
 * superposición por grupo, no ocupa un nivel.
 */
final class TimeColumnPlacer
{
    private function __construct() {}

    /**
     * @param  list<Span>  $spans  Ya ordenados por `eventOrder`. `start`/`end` en cualquier unidad, ya ajustados por la altura mínima; una clave única por span.
     * @param  bool  $strict  `eventOrderStrict`: si dos eventos se solapan, el de menor orden queda más a la izquierda.
     * @param  int|null  $maxStack  `eventMaxStack`: máximo de columnas base por día; los que no caben van a un grupo "+N más". `null` = sin límite.
     * @param  bool  $slotEventOverlap  El evento ocupa el doble de ancho y pisa al siguiente (por defecto en la vista).
     */
    public static function place(
        array $spans,
        bool $strict = false,
        ?int $maxStack = null,
        bool $slotEventOverlap = true,
    ): TimeColumnPlacement {
        $sources = [];

        foreach (array_values($spans) as $orderIndex => $span) {
            $sources[] = new Source($span->key, $span->start, $span->end, $span->isStart, $span->isEnd, $orderIndex, $span);
        }

        $built = PlacementKernel::buildLevels($sources, $strict, $maxStack === null ? INF : max(0, $maxStack));
        $geometry = self::position($built->levels);

        $placed = [];
        foreach (PlacementKernel::sortByAxisOrder(self::flatten($built->levels)) as $source) {
            [$level, $levelCoord, $thickness, $backwardDepth, $forwardDepth] = $geometry[$source->orderIndex];
            [$left, $right] = self::horizontal($levelCoord, $thickness, $slotEventOverlap);

            $placed[] = new PlacedEvent(
                span: $source->payload,
                level: $level,
                levelCoord: $levelCoord,
                thickness: $thickness,
                backwardDepth: $backwardDepth,
                forwardDepth: $forwardDepth,
                left: $left,
                right: $right,
                zIndex: $backwardDepth + 1,
                marginEnd: $slotEventOverlap && $forwardDepth > 0,
            );
        }

        // Los excluidos forman un grupo por componente conexa de solapes. Sin
        // tax ni slicing: los grupos se forman cuando la admisión ya terminó.
        $hidden = array_map(
            fn (HiddenSliceGroup $group): HiddenGroup => new HiddenGroup(
                $group->start,
                $group->end,
                array_map(fn (Slice $slice): Span => $slice->source->payload, $group->hiddenSlices),
                $group->key,
            ),
            PlacementKernel::groupLaterallyIntersecting(PlacementKernel::wholeSlices($built->excluded)),
        );

        return new TimeColumnPlacement($placed, $hidden);
    }

    /**
     * El rectángulo horizontal final (`computeSegHStyle`).
     *
     * Con `slotEventOverlap` el ancho se dobla, sin pasar del borde (1.0): un
     * evento solapado se ve más ancho y el siguiente lo pisa. `right` es la
     * distancia del borde lejano al de la columna. Un error de coma flotante
     * (0.1 + 0.9 = 1.0000000000000002) no puede dar un `right` negativo.
     *
     * @return array{0: float, 1: float} [left, right]
     */
    private static function horizontal(float $levelCoord, float $thickness, bool $slotEventOverlap): array
    {
        $near = $levelCoord;
        $far = $levelCoord + $thickness;

        if ($slotEventOverlap) {
            $far = min(1.0, $near + ($far - $near) * 2);
        }

        return [$near, max(0.0, 1.0 - $far)];
    }

    /**
     * Convierte los niveles retenidos en rectángulos normalizados.
     *
     * Cada componente conexa de colisiones comparte columnas base del mismo ancho.
     * Un evento se ensancha por las columnas más profundas hasta la primera que
     * contiene un colisionador. Un barrido de intersecciones da la pertenencia a
     * la componente, el tope de la expansión y las cadenas de profundidad.
     *
     * Se indexa por `orderIndex`, no por clave: es único por construcción y no
     * obliga a que los ids de los eventos lo sean.
     *
     * @param  list<list<Source>>  $levels
     * @return array<int, array{0: int, 1: float, 2: float, 3: int, 4: int}> orderIndex => [nivel, coord, grosor, profundidad atrás, profundidad adelante]
     */
    private static function position(array $levels): array
    {
        $flat = self::flatten($levels);
        $levelCount = count($levels);
        $levelOf = [];

        foreach ($levels as $levelIndex => $level) {
            foreach ($level as $source) {
                $levelOf[$source->orderIndex] = $levelIndex;
            }
        }

        // El orden de $flat importa: cada colocado va antes que sus colisionadores más profundos.
        $parent = [];
        foreach ($flat as $source) {
            $parent[$source->orderIndex] = $source->orderIndex;
        }

        $colliders = [];
        foreach ($flat as $source) {
            $id = $source->orderIndex;
            $found = [];

            for ($deeper = $levelOf[$id] + 1; $deeper < $levelCount; $deeper++) {
                foreach (SpanMath::findIntersections($levels[$deeper], $source) as $collider) {
                    $found[] = $collider->orderIndex;
                }
            }

            $colliders[$id] = $found;
            foreach ($found as $colliderId) {
                self::union($parent, $id, $colliderId);
            }
        }

        $maxLevelByRoot = [];
        foreach ($flat as $source) {
            $root = self::findRoot($parent, $source->orderIndex);
            $maxLevelByRoot[$root] = max($maxLevelByRoot[$root] ?? 0, $levelOf[$source->orderIndex]);
        }

        // Cadenas de colisión más largas, por programación dinámica sobre la
        // adyacencia. Los dos barridos van en sentidos opuestos para que cada
        // dependencia esté cerrada antes de aportar a la siguiente.
        $backward = array_fill_keys(array_keys($parent), 0);
        $forward = $backward;

        foreach ($flat as $source) {
            $depth = $backward[$source->orderIndex] + 1;
            foreach ($colliders[$source->orderIndex] as $colliderId) {
                $backward[$colliderId] = max($backward[$colliderId], $depth);
            }
        }

        for ($i = count($flat) - 1; $i >= 0; $i--) {
            $id = $flat[$i]->orderIndex;
            $depth = 0;
            foreach ($colliders[$id] as $colliderId) {
                $depth = max($depth, $forward[$colliderId] + 1);
            }
            $forward[$id] = $depth;
        }

        $geometry = [];
        foreach ($flat as $source) {
            $id = $source->orderIndex;
            $columns = $maxLevelByRoot[self::findRoot($parent, $id)] + 1;

            // Se expande hasta el colisionador más cercano. Los colisionadores
            // siempre están en su componente, así que sus niveles caben en ella.
            $farLevel = $columns;
            foreach ($colliders[$id] as $colliderId) {
                $farLevel = min($farLevel, $levelOf[$colliderId]);
            }

            $geometry[$id] = [
                $levelOf[$id],
                $levelOf[$id] / $columns,
                ($farLevel - $levelOf[$id]) / $columns,
                $backward[$id],
                $forward[$id],
            ];
        }

        // `int / int` da int cuando divide exacto (0 / 3): el contrato pide float.
        return array_map(
            fn (array $g): array => [$g[0], (float) $g[1], (float) $g[2], $g[3], $g[4]],
            $geometry,
        );
    }

    /**
     * @param  list<list<Source>>  $levels
     * @return list<Source>
     */
    private static function flatten(array $levels): array
    {
        return $levels === [] ? [] : array_merge(...$levels);
    }

    /**
     * Raíz del conjunto en el union-find, iterativa y con compresión de caminos:
     * una cadena larga de colisiones no puede agotar la pila.
     *
     * @param  array<int, int>  $parent
     */
    private static function findRoot(array &$parent, int $id): int
    {
        $root = $id;
        while ($parent[$root] !== $root) {
            $root = $parent[$root];
        }

        while ($parent[$id] !== $root) {
            $next = $parent[$id];
            $parent[$id] = $root;
            $id = $next;
        }

        return $root;
    }

    /** @param  array<int, int>  $parent */
    private static function union(array &$parent, int $first, int $second): void
    {
        $firstRoot = self::findRoot($parent, $first);
        $secondRoot = self::findRoot($parent, $second);

        if ($firstRoot !== $secondRoot) {
            $parent[$secondRoot] = $firstRoot;
        }
    }
}
