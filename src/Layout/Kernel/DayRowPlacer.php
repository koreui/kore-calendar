<?php

namespace KoreUi\Calendar\Layout\Kernel;

use InvalidArgumentException;

/**
 * Adaptador de la vista mes: coloca los spans de UNA fila (o del carril de todo
 * el día de la rejilla horaria, que es una vista mes de una fila) sobre sus
 * columnas, con límite numérico de eventos por celda.
 *
 * Port de `packages/preact/src/daygrid/seg-placement-adapter.ts` (FullCalendar
 * v7.1.3, MIT; ver THIRD-PARTY-NOTICES.md), sin el modo "auto" por píxeles:
 * `resolveDayGridPlacementMode`, `computeDayGridDomCandidateMaxLevels`,
 * `computeDayGridMoreLinkLevelTax` y `buildDayGridPopoverSegs`. La referencia
 * del modo sin medición es `seg-placement/print.ts`: `buildSegLevels` y
 * `placeExtraSlicesInLevels` con una tasa fija, sin nada que medir.
 *
 * Geometría pura. Las columnas son enteros 0..$columns-1; el que corta los
 * eventos por fila, descarta los días ocultos y decide `isStart`/`isEnd` es el
 * llamador (docs/calendar.md §7.2).
 */
final class DayRowPlacer
{
    /** Sin ningún límite. */
    public const MODE_UNLIMITED = 'unlimited';

    /** `dayMaxEvents`: N eventos por celda; el enlace no cuenta como una fila. */
    public const MODE_MAX_EVENTS = 'maxEvents';

    /** `dayMaxEventRows`: N filas por celda; el enlace "+N más" ocupa una de ellas. */
    public const MODE_MAX_EVENT_ROWS = 'maxEventRows';

    private function __construct() {}

    /**
     * Coloca una fila.
     *
     * Precedencia de modos (FullCalendar `resolveDayGridPlacementMode`, tabla
     * 1.6 del catálogo): `$maxEvents` gana sobre `$maxEventRows` si vienen los
     * dos; solo `$maxEventRows` cobra una fila al enlace; ninguno es ilimitado.
     * Un límite negativo se trata como 0.
     *
     * @param  list<Span>  $spans  Ya ordenados por `eventOrder`. `start`/`end` son columnas enteras con `0 <= start < end <= $columns`.
     * @param  int  $columns  Columnas visibles de la fila.
     * @param  bool  $strict  `eventOrderStrict`: el orden de evento es parte de la topología.
     * @param  bool  $slicing  `eventSlicing` (por defecto en FC 7.1): un evento que no cabe entero se muestra a trozos.
     *
     * @throws InvalidArgumentException Si un span sale de la fila, es vacío o repite clave.
     */
    public static function place(
        array $spans,
        int $columns,
        ?int $maxEvents = null,
        ?int $maxEventRows = null,
        bool $strict = false,
        bool $slicing = true,
    ): DayRowPlacement {
        if ($columns < 1) {
            throw new InvalidArgumentException("Una fila necesita al menos una columna; llegó {$columns}.");
        }

        $maxEvents = $maxEvents === null ? null : max(0, $maxEvents);
        $maxEventRows = $maxEventRows === null ? null : max(0, $maxEventRows);

        $sources = self::sources($spans);

        foreach ($sources as $source) {
            if ($source->start < 0 || $source->end > $columns) {
                throw new InvalidArgumentException(
                    "El span '{$source->payload->key}' [{$source->start}, {$source->end}) sale de las {$columns} columnas de la fila.",
                );
            }
        }

        $mode = self::mode($maxEvents, $maxEventRows);
        $built = PlacementKernel::buildLevels(
            $sources,
            $strict,
            self::candidateMaxLevels($mode, $maxEvents, $maxEventRows),
        );
        $placement = PlacementKernel::placeExtras(
            PlacementKernel::wholeSliceLevels($built->levels),
            PlacementKernel::wholeSlices($built->excluded),
            $strict,
            $slicing,
            self::moreLinkLevelTax($mode),
        );

        // Altura unitaria: la fila vertical sin medir (resolveLevelCoords sin alturas).
        $rows = PlacementKernel::resolveLevelCoords($placement->sliceLevels)->sliceCoords;

        $entries = [];
        foreach ($placement->sliceLevels as $level => $slices) {
            foreach ($slices as $slice) {
                $entries[] = [$slice, $level, (int) $rows[$slice->key()]];
            }
        }

        // Orden del DOM: columna a columna y, dentro de una, por orden de evento
        // (federateSlicesByStart). Un lector de pantalla recorre la fila en orden.
        usort($entries, fn (array $a, array $b): int => ($a[0]->start <=> $b[0]->start)
            ?: PlacementKernel::compareByEventOrder($a[0], $b[0]));

        $visible = [];
        $usedRows = array_fill(0, $columns, 0);
        $rowCount = 0;

        foreach ($entries as [$slice, $level, $row]) {
            $visible[] = new PlacedSlice(
                span: $slice->source->payload,
                start: $slice->start,
                end: $slice->end,
                isStart: $slice->isStart,
                isEnd: $slice->isEnd,
                level: $level,
                row: $row,
                isSlice: $slice->isPartial(),
                key: $slice->key(),
            );

            $rowCount = max($rowCount, $row + 1);
            for ($column = (int) $slice->start; $column < $slice->end; $column++) {
                $usedRows[$column] = max($usedRows[$column], $row + 1);
            }
        }

        $hiddenInOrder = PlacementKernel::sortByEventOrder($placement->hiddenSlices);
        $cells = [];

        for ($column = 0; $column < $columns; $column++) {
            $cells[$column] = self::buildCell($column, $sources, $hiddenInOrder, $usedRows[$column]);
        }

        return new DayRowPlacement($visible, $cells, $rowCount, $placement->hiddenSlices);
    }

    /**
     * Convierte los spans, ya ordenados, en fuentes del kernel (`buildDayGridSegSources`).
     *
     * La clave de la fuente es `{span.key}:{columnaInicial}`: igual que
     * `getDayGridSegKey`. El mismo evento puede aparecer en varias filas con la
     * misma `span.key`, pero en cada fila empieza en una columna distinta, y
     * dentro de una fila la clave tiene que ser única porque de ella salen el
     * `wire:key` y la coordenada de cada trozo.
     *
     * @param  list<Span>  $spans
     * @return list<Source>
     *
     * @throws InvalidArgumentException
     */
    public static function sources(array $spans): array
    {
        $sources = [];
        $seen = [];

        foreach (array_values($spans) as $orderIndex => $span) {
            if ((int) $span->start != $span->start || (int) $span->end != $span->end) {
                throw new InvalidArgumentException("El span '{$span->key}' tiene columnas no enteras [{$span->start}, {$span->end}).");
            }
            if ($span->end <= $span->start) {
                throw new InvalidArgumentException("El span '{$span->key}' está vacío: [{$span->start}, {$span->end}).");
            }

            $key = $span->key.':'.(int) $span->start;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Dos spans comparten la clave '{$key}': la clave tiene que ser única dentro de la fila.");
            }
            $seen[$key] = true;

            $sources[] = new Source($key, (int) $span->start, (int) $span->end, $span->isStart, $span->isEnd, $orderIndex, $span);
        }

        return $sources;
    }

    /**
     * El modo según los dos límites (`resolveDayGridPlacementMode`, sin el caso
     * booleano `true` = auto, que depende de medir).
     *
     * @return self::MODE_*
     */
    public static function mode(?int $maxEvents, ?int $maxEventRows): string
    {
        if ($maxEvents !== null) {
            return self::MODE_MAX_EVENTS;
        }
        if ($maxEventRows !== null) {
            return self::MODE_MAX_EVENT_ROWS;
        }

        return self::MODE_UNLIMITED;
    }

    /**
     * Los niveles lógicos que caben antes de excluir, sin la tasa del enlace
     * (`computeDayGridDomCandidateMaxLevels`). `INF` si no hay tope.
     *
     * @param  self::MODE_*  $mode
     */
    public static function candidateMaxLevels(string $mode, ?int $maxEvents, ?int $maxEventRows): int|float
    {
        return match ($mode) {
            self::MODE_MAX_EVENTS => $maxEvents ?? INF,
            self::MODE_MAX_EVENT_ROWS => $maxEventRows ?? INF,
            default => INF,
        };
    }

    /**
     * Niveles lógicos que cobra un enlace "+N más" a su columna
     * (`computeDayGridMoreLinkLevelTax`). Solo `dayMaxEventRows` lo cuenta como
     * una de sus N filas; con `dayMaxEvents: 2` caben dos eventos Y el enlace.
     *
     * @param  self::MODE_*  $mode
     */
    public static function moreLinkLevelTax(string $mode): int
    {
        return $mode === self::MODE_MAX_EVENT_ROWS ? 1 : 0;
    }

    /**
     * Recorta un span a una columna, para el popover (`cutSegToColumn`): ocupa
     * solo esa celda, y `isStart`/`isEnd` siguen diciendo la verdad sobre los
     * bordes REALES del evento (es el primer día solo si empieza en esa
     * columna).
     */
    public static function cutToColumn(Span $span, int $column): Span
    {
        return new Span(
            $span->key,
            $column,
            $column + 1,
            $span->payload,
            $span->isStart && $span->start == $column,
            $span->isEnd && $span->end - 1 == $column,
        );
    }

    /**
     * Una celda: las fuentes ocultas que la cruzan (una vez cada una) y todas las
     * que la cruzan, ambas en orden de evento (`buildDayGridPopoverSegs`).
     *
     * @param  list<Source>  $sources
     * @param  list<Slice>  $hiddenInOrder
     */
    private static function buildCell(int $column, array $sources, array $hiddenInOrder, int $usedRows): DayCell
    {
        $hidden = [];
        $seen = [];

        foreach ($hiddenInOrder as $slice) {
            if ($slice->start < $column + 1 && $column < $slice->end && ! isset($seen[$slice->source->orderIndex])) {
                $seen[$slice->source->orderIndex] = true;
                $hidden[] = $slice->source->payload;
            }
        }

        $all = [];
        foreach ($sources as $source) {
            if ($source->start < $column + 1 && $column < $source->end) {
                $all[] = $source->payload;
            }
        }

        return new DayCell($column, $hidden, $all, $usedRows);
    }
}
