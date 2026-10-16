<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * El kernel de colocación: un solo algoritmo para la vista mes y la rejilla horaria.
 *
 * Port de `packages/preact/src/seg-placement/kernel.ts` (FullCalendar v7.1.3,
 * MIT; ver THIRD-PARTY-NOTICES.md). Es geometría pura: no sabe de fechas, de
 * eventos ni de HTML, solo de tramos numéricos `[start, end)`. En la vista mes los
 * tramos son columnas y los niveles son filas; en la rejilla horaria el eje
 * lateral es el tiempo y los niveles son las columnas del día. `DayRowPlacer` y
 * `TimeColumnPlacer` son los dos adaptadores que lo usan.
 *
 * Modelo:
 * - Una `Source` es un segmento de entrada, ya ordenado por `eventOrder` (el
 *   `orderIndex` es su posición en la lista).
 * - Un `Slice` es un trozo de una fuente: entera, o un fragmento que el kernel
 *   cortó para mostrarla solo donde no estorba.
 * - Un nivel es una lista de trozos sin solapes laterales; el índice del nivel
 *   exterior es su coordenada lógica, sin dimensiones.
 *
 * Qué NO se porta: `buildLevelLimitedLayout`/`buildPixelLimitedLayout` y el
 * podado por píxeles. El modo "auto" de FullCalendar mide alturas en el
 * navegador; aquí el límite es siempre numérico (docs/calendar.md §7.2). La
 * coordenada vertical sin medir sale de `resolveLevelCoords()` con altura unitaria.
 *
 * Determinista y sin recursión.
 */
final class PlacementKernel
{
    /**
     * Épsilon permisivo para comparar coordenadas y presupuestos. Es el mismo que
     * usa FullCalendar; las intersecciones laterales NO lo usan (son exactas: un
     * borde que solo se toca no es un solape), y se evita así que un error de
     * coma flotante (#5549) invente colisiones falsas.
     */
    public const GEOMETRY_TOLERANCE = 0.000_001;

    private function __construct() {}

    /* ====================================================================
     * Construcción de niveles de fuentes enteras
     * ================================================================= */

    /**
     * Reparte las fuentes en niveles sin consultar ninguna dimensión.
     *
     * Recorre las fuentes en orden de evento y coloca cada una en el primer nivel
     * donde no interseca con nada. En modo estricto no reutiliza huecos: va un
     * nivel por debajo del más profundo que interseca, y así, si dos eventos se
     * solapan, el de menor `orderIndex` queda en un nivel menor.
     *
     * Una fuente que no cabe (`nivel >= $maxLevels`) se EXCLUYE sin ocupar sitio,
     * y las siguientes pueden usar su hueco (FullCalendar #5883, "gaps with
     * eventOrder"). Las excluidas conservan el orden de evento.
     *
     * @param  list<Source>  $sources  Ya ordenadas por `eventOrder`.
     */
    public static function buildLevels(array $sources, bool $strict, int|float $maxLevels = INF): BuiltLevels
    {
        $levels = [];
        $excluded = [];

        foreach ($sources as $source) {
            $levelIndex = self::findPackedLevelIndex($levels, $source, $strict);

            if ($levelIndex >= $maxLevels) {
                $excluded[] = $source;

                continue;
            }

            while (count($levels) <= $levelIndex) {
                $levels[] = [];
            }

            SpanMath::insertLaterally($levels[$levelIndex], $source);
        }

        return new BuiltLevels($levels, $excluded);
    }

    /**
     * El nivel al que pertenece un tramo: el más superficial que esté libre o,
     * si reusar huecos está prohibido, justo debajo del ocupante más profundo
     * que interseca. `count($levels)` significa "abrir un nivel nuevo".
     *
     * @param  list<list<object>>  $levels
     */
    private static function findPackedLevelIndex(array $levels, object $span, bool $strict): int
    {
        $levelIndex = 0;

        if ($strict) {
            foreach ($levels as $i => $level) {
                if (SpanMath::findIntersections($level, $span) !== []) {
                    $levelIndex = $i + 1;
                }
            }

            return $levelIndex;
        }

        for ($count = count($levels); $levelIndex < $count; $levelIndex++) {
            if (SpanMath::findIntersections($levels[$levelIndex], $span) === []) {
                break;
            }
        }

        return $levelIndex;
    }

    /**
     * @param  list<Source>  $sources
     * @return list<Slice>
     */
    public static function wholeSlices(array $sources): array
    {
        return array_map(Slice::whole(...), $sources);
    }

    /**
     * @param  list<list<Source>>  $levels
     * @return list<list<Slice>>
     */
    public static function wholeSliceLevels(array $levels): array
    {
        return array_map(self::wholeSlices(...), $levels);
    }

    /* ====================================================================
     * Reparto de los extras
     * ================================================================= */

    /**
     * Reparte los trozos que no cupieron sobre niveles ya llenos, en orden de
     * evento. Puede reutilizar huecos de los niveles recibidos pero nunca crea
     * niveles nuevos.
     *
     * - Sin `$slicing`, un trozo que falla se oculta entero.
     * - Con `$slicing`, cada nivel ofrece sus tramos libres máximos y gana el plan
     *   que equilibra longitud expuesta y fragmentación: hasta 3 tramos, con
     *   penalización de 0.15 por cada tramo extra. Un evento de varios días se
     *   muestra a trozos y se oculta solo donde estorba.
     * - Cada trozo oculto alimenta una cobertura de enlaces. Con `$tax > 0`, solo
     *   los tramos de cobertura NUEVOS reservan el último nivel para el enlace
     *   "+N más" (`dayMaxEventRows`: el enlace cuenta como una fila), desalojando
     *   a quien lo ocupara. Solo se distingue 0 de >0, como en FullCalendar.
     *
     * Iterativo (pila explícita): FullCalendar #7462.
     *
     * PRECONDICIÓN: `$extraSlices` está ordenado por orden de evento. Los niveles
     * de entrada no se modifican; la topología final va en el resultado.
     *
     * @param  list<list<Slice>>  $sliceLevels
     * @param  list<Slice>  $extraSlices
     */
    public static function placeExtras(
        array $sliceLevels,
        array $extraSlices,
        bool $strict,
        bool $slicing,
        int $tax = 0,
    ): ExtraSlicePlacement {
        return (new ExtraPlacer($sliceLevels, $strict, $slicing, $tax))->run($extraSlices);
    }

    /* ====================================================================
     * Grupos de "+N más"
     * ================================================================= */

    /**
     * Une transitivamente los trozos ocultos que se intersecan estrictamente. Los
     * adyacentes quedan en grupos separados: cada grupo es un enlace distinto.
     * Los grupos salen ordenados por inicio; los miembros, en orden de evento,
     * una vez por fuente (varios fragmentos de una fuente se funden en su casco).
     *
     * @param  list<Slice>  $hiddenSlices
     * @return list<HiddenSliceGroup>
     */
    public static function groupLaterallyIntersecting(array $hiddenSlices): array
    {
        $groups = [];

        foreach ($hiddenSlices as $slice) {
            HiddenGroups::add($groups, $slice);
        }

        return HiddenGroups::finalize($groups);
    }

    /* ====================================================================
     * Coordenadas por nivel
     * ================================================================= */

    /**
     * Resuelve la coordenada vertical de cada trozo sin modificar la entrada.
     *
     * Cada trozo admitido se archiva DEBAJO de todo trozo admitido con el que
     * interseca, nunca en un hueco superior: el orden de los niveles refleja el
     * orden de apilado y el orden estricto sobrevive sin consultarlo. Es
     * `fila = máx(fila + 1 de los ya colocados que intersecan)`.
     *
     * Sin `$heights` cada trozo mide 1: la coordenada es una fila de 0 en
     * adelante, sin medir nada. Es lo que usa la vista mes en el servidor.
     * Con `$heights` (`Slice::key()` => altura), un trozo sin altura queda
     * pendiente (`isSettled` falso) y no bloquea a los siguientes; uno que supera
     * `$maxPixels` se excluye de forma definitiva y tampoco bloquea.
     *
     * @param  list<list<Slice>>  $sliceLevels
     * @param  array<string, int|float>|null  $heights
     */
    public static function resolveLevelCoords(
        array $sliceLevels,
        ?array $heights = null,
        int|float $maxPixels = INF,
    ): LevelCoordsResolution {
        $placementLevels = [];
        $coords = [];
        $isSettled = true;
        $excluded = [];

        foreach ($sliceLevels as $level) {
            foreach ($level as $slice) {
                $height = self::heightOf($slice, $heights);

                if ($height === null) {
                    $isSettled = false;

                    continue;
                }

                [$levelCoord, $packedLevelIndex] = self::lateralPlacement($placementLevels, $slice, $coords, $heights);

                if ($levelCoord + $height <= $maxPixels + self::GEOMETRY_TOLERANCE) {
                    // Reempaquetar los admitidos funde niveles: hay que mantener el orden lateral.
                    while (count($placementLevels) <= $packedLevelIndex) {
                        $placementLevels[] = [];
                    }

                    SpanMath::insertLaterally($placementLevels[$packedLevelIndex], $slice);
                    $coords[$slice->key()] = $levelCoord;
                } else {
                    $excluded[] = $slice;
                }
            }
        }

        return new LevelCoordsResolution($placementLevels, $coords, $isSettled, $excluded);
    }

    /** @param  array<string, int|float>|null  $heights */
    private static function heightOf(Slice $slice, ?array $heights): int|float|null
    {
        return $heights === null ? 1 : ($heights[$slice->key()] ?? null);
    }

    /**
     * El fondo y el nivel más profundos, medidos y con coordenada, entre los
     * trozos que tocan el tramo (`computeLateralSpanPlacement`).
     *
     * @param  list<list<Slice>>  $levels
     * @param  array<string, int|float>  $coords
     * @param  array<string, int|float>|null  $heights
     * @return array{0: int|float, 1: int} [fondo, índice de nivel]
     */
    private static function lateralPlacement(array $levels, object $span, array $coords, ?array $heights): array
    {
        $bottom = 0;
        $levelIndex = 0;

        foreach ($levels as $i => $level) {
            foreach (SpanMath::findIntersections($level, $span) as $slice) {
                $key = $slice->key();
                $height = self::heightOf($slice, $heights);

                if (array_key_exists($key, $coords) && $height !== null) {
                    $bottom = max($bottom, $coords[$key] + $height);
                    $levelIndex = $i + 1;
                }
            }
        }

        return [$bottom, $levelIndex];
    }

    /* ====================================================================
     * Utilidades de trozos
     * ================================================================= */

    /** Clave estable de un trozo (ver `Slice::key()`). */
    public static function sliceKey(Slice $slice): string
    {
        return $slice->key();
    }

    public static function isPartialSlice(Slice $slice): bool
    {
        return $slice->isPartial();
    }

    /**
     * Orden de evento, luego inicio, luego el más largo primero: a igual evento,
     * un trozo ancho va antes que uno estrecho que empieza en el mismo sitio.
     */
    public static function compareByEventOrder(Slice $a, Slice $b): int
    {
        return ($a->source->orderIndex <=> $b->source->orderIndex)
            ?: ($a->start <=> $b->start)
            ?: ($b->end <=> $a->end);
    }

    /**
     * @param  list<Slice>  $slices
     * @return list<Slice>
     */
    public static function sortByEventOrder(array $slices): array
    {
        usort($slices, self::compareByEventOrder(...));

        return $slices;
    }

    /**
     * Orden por inicio sobre el eje y luego por orden de evento resuelto. Es el
     * orden del DOM en la rejilla horaria: los lectores de pantalla leen en orden
     * temporal, y la prioridad de `eventOrder` solo desempata.
     *
     * Acepta cualquier objeto con `start` y `source->orderIndex`, o un `Source`.
     */
    public static function compareByAxisOrder(object $a, object $b): int
    {
        return ($a->start <=> $b->start)
            ?: (self::orderIndexOf($a) <=> self::orderIndexOf($b));
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function sortByAxisOrder(array $items): array
    {
        usort($items, self::compareByAxisOrder(...));

        return $items;
    }

    private static function orderIndexOf(object $item): int
    {
        return $item instanceof Source ? $item->orderIndex : $item->source->orderIndex;
    }
}
