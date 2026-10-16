<?php

namespace KoreUi\Calendar\Tests\Layout\Kernel\Support;

use Closure;
use KoreUi\Calendar\Layout\Kernel\DayCell;
use KoreUi\Calendar\Layout\Kernel\DayRowPlacement;
use KoreUi\Calendar\Layout\Kernel\ExtraSlicePlacement;
use KoreUi\Calendar\Layout\Kernel\HiddenSliceGroup;
use KoreUi\Calendar\Layout\Kernel\PlacementKernel;
use KoreUi\Calendar\Layout\Kernel\Slice;
use KoreUi\Calendar\Layout\Kernel\Source;
use KoreUi\Calendar\Layout\Kernel\Span;
use PHPUnit\Framework\Assert;

/**
 * Ayudas compartidas por los tests del kernel. Son las funciones auxiliares de
 * `kernel-correctness.test.ts` y `kernel-fuzz.test.ts` de FullCalendar v7.1.3
 * (MIT; ver THIRD-PARTY-NOTICES.md), en una clase para no chocar con las
 * funciones globales de otros tests del paquete.
 */
final class Fixtures
{
    /**
     * Las fuentes de un escenario (`segs()` en kernel-correctness.test.ts): la
     * clave es `{id}:{inicio}`, igual que la clave de una fuente de la vista mes.
     *
     * @param  list<array{0: string, 1: int|float, 2: int|float}>  $specs
     * @return list<Source>
     */
    public static function segs(array $specs): array
    {
        $sources = [];

        foreach ($specs as $orderIndex => [$id, $start, $end]) {
            $sources[] = new Source("{$id}:{$start}", $start, $end, true, true, $orderIndex, $id);
        }

        return $sources;
    }

    /** @return array{0: string, 1: int|float, 2: int|float} */
    public static function projectSlice(Slice $slice): array
    {
        return [$slice->source->payload, $slice->start, $slice->end];
    }

    /**
     * @param  list<Slice>  $slices
     * @return list<array{0: string, 1: int|float, 2: int|float}>
     */
    public static function projectSlices(array $slices): array
    {
        return array_map(self::projectSlice(...), $slices);
    }

    /**
     * @param  list<list<Source>>  $levels
     * @return list<list<string>>
     */
    public static function projectSegLevels(array $levels): array
    {
        return array_map(fn (array $level): array => array_map(fn (Source $s): string => $s->payload, $level), $levels);
    }

    /**
     * @param  list<list<Slice>>  $levels
     * @return list<Slice>
     */
    public static function flat(array $levels): array
    {
        return $levels === [] ? [] : array_merge(...$levels);
    }

    /** @return list<HiddenSliceGroup> */
    public static function hiddenGroupsOf(ExtraSlicePlacement $result): array
    {
        return PlacementKernel::groupLaterallyIntersecting($result->hiddenSlices);
    }

    /**
     * Audita la cobertura exacta con la lista plana de ocultos, no con los grupos
     * fundidos en casco: cada fuente tiene que quedar particionada en trozos
     * visibles y ocultos, contiguos, con los flags de continuidad correctos
     * (`auditCoverage` de kernel-correctness.test.ts).
     *
     * @param  list<Source>  $sources
     * @param  list<list<Slice>>  $levels
     * @param  list<Slice>  $hiddenSlices
     */
    public static function auditCoverage(array $sources, array $levels, array $hiddenSlices, string $label = ''): void
    {
        $pieces = array_merge(self::flat($levels), $hiddenSlices);

        foreach ($sources as $source) {
            $sourcePieces = array_values(array_filter($pieces, fn (Slice $p): bool => $p->source === $source));
            usort($sourcePieces, fn (Slice $a, Slice $b): int => ($a->start <=> $b->start) ?: ($a->end <=> $b->end));

            self::invariant($sourcePieces !== [], "{$source->key} desapareció", $label);

            $cursor = $source->start;
            foreach ($sourcePieces as $piece) {
                self::invariant($piece->start == $cursor, "{$source->key} tiene un hueco o un solape", $label);
                self::invariant($piece->end > $piece->start, "{$source->key} tiene un trozo vacío", $label);
                self::invariant(
                    $piece->isStart === ($source->isStart && $piece->start == $source->start),
                    "{$source->key} tiene un isStart incorrecto",
                    $label,
                );
                self::invariant(
                    $piece->isEnd === ($source->isEnd && $piece->end == $source->end),
                    "{$source->key} tiene un isEnd incorrecto",
                    $label,
                );
                $cursor = $piece->end;
            }
            self::invariant($cursor == $source->end, "{$source->key} tiene una cobertura incompleta", $label);
        }
    }

    public static function intersects(object $left, object $right): bool
    {
        return $left->start < $right->end && $right->start < $left->end;
    }

    /**
     * Sin solapes laterales dentro de un nivel y, en modo estricto, el de menor
     * orden queda en un nivel menor (`auditLevels` de kernel-fuzz.test.ts).
     *
     * @param  list<list<Slice>>  $levels
     */
    public static function auditLevels(array $levels, bool $strict, string $label): void
    {
        foreach ($levels as $levelIndex => $level) {
            for ($i = 1, $count = count($level); $i < $count; $i++) {
                self::invariant($level[$i - 1]->end <= $level[$i]->start, "el nivel {$levelIndex} se solapa lateralmente", $label);
            }
        }

        if (! $strict) {
            return;
        }

        $entries = [];
        foreach ($levels as $levelIndex => $level) {
            foreach ($level as $slice) {
                $entries[] = [$slice, $levelIndex];
            }
        }

        foreach ($entries as [$left, $leftLevel]) {
            foreach ($entries as [$right, $rightLevel]) {
                if (
                    $left->source !== $right->source
                    && $left->source->orderIndex < $right->source->orderIndex
                    && self::intersects($left, $right)
                ) {
                    self::invariant($leftLevel < $rightLevel, 'el orden de evento estricto se invirtió', $label);
                }
            }
        }
    }

    /**
     * Grupos "+N más": disjuntos, no vacíos, con la clave del primer miembro, una
     * vez cada fuente, en orden de evento, dentro del tramo del grupo y con los
     * flags de continuidad correctos (`auditGroups` de kernel-fuzz.test.ts).
     *
     * @param  list<HiddenSliceGroup>  $groups
     */
    public static function auditGroups(array $groups, string $label): void
    {
        $count = count($groups);

        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                self::invariant(! self::intersects($groups[$left], $groups[$right]), 'los grupos ocultos se intersecan entre sí', $label);
            }
        }

        foreach ($groups as $group) {
            self::invariant($group->hiddenSlices !== [], 'grupo oculto vacío', $label);
            self::invariant($group->key === $group->hiddenSlices[0]->key(), 'la clave del grupo no es la de su primer trozo', $label);

            $seen = [];
            $previousOrder = -1;

            foreach ($group->hiddenSlices as $slice) {
                self::invariant(! in_array($slice->source, $seen, true), 'el grupo lista una fuente dos veces pese a fundir el casco', $label);
                $seen[] = $slice->source;
                self::invariant($slice->source->orderIndex >= $previousOrder, 'miembros del grupo fuera de orden de evento', $label);
                $previousOrder = $slice->source->orderIndex;
                self::invariant($slice->start >= $group->start && $slice->end <= $group->end, 'un miembro se sale del tramo del grupo', $label);
                self::invariant(
                    $slice->isStart === ($slice->source->isStart && $slice->start == $slice->source->start)
                    && $slice->isEnd === ($slice->source->isEnd && $slice->end == $slice->source->end),
                    'un miembro del grupo tiene mal los flags de continuidad',
                    $label,
                );
            }
        }
    }

    /**
     * El generador pseudoaleatorio de kernel-fuzz.test.ts (`createRandom`): un
     * congruencial lineal de 32 bits. Con la misma semilla produce exactamente
     * los mismos escenarios que el original, que es lo que permite comparar un
     * fallo con el de FullCalendar.
     *
     * @return Closure(): float
     */
    public static function createRandom(int $seed): Closure
    {
        $state = $seed;

        return function () use (&$state): float {
            $state = ($state * 1_664_525 + 1_013_904_223) & 0xFFFF_FFFF;

            return $state / 0x1_0000_0000;
        };
    }

    /**
     * El escenario de una semilla (`buildScenario`): de 6 a 23 fuentes sobre 7
     * columnas, de 1 a 4 de ancho.
     *
     * @return list<Source>
     */
    public static function buildScenario(int $seed): array
    {
        $random = self::createRandom($seed);
        $count = 6 + (int) floor($random() * 18);
        $sources = [];

        for ($orderIndex = 0; $orderIndex < $count; $orderIndex++) {
            $start = (int) floor($random() * 7);
            $end = min(7, $start + 1 + (int) floor($random() * 4));
            $id = "{$seed}:{$orderIndex}";

            $sources[] = new Source("{$id}:{$start}", $start, $end, true, true, $orderIndex, $id);
        }

        return $sources;
    }

    /**
     * Falla con la etiqueta del escenario. Usa una aserción de PHPUnit para que
     * cuente en el total y para que un bucle de 300 semillas indique cuál rompió.
     */
    public static function invariant(bool $condition, string $message, string $label): void
    {
        Assert::assertTrue($condition, $message."\n".$label);
    }

    /* ====================================================================
     * Vista mes (DayRowPlacer)
     * ================================================================= */

    /** `makeSeg()` de daygrid-adapter.test.ts: el span de un evento en una fila. */
    public static function span(string $id, int $start, int $end, bool $isStart = true, bool $isEnd = true): Span
    {
        return new Span($id, $start, $end, null, $isStart, $isEnd);
    }

    /**
     * Las claves de los trozos visibles que EMPIEZAN en una columna
     * (`column.renderSlices.map(getSliceKey)` de los tests de FullCalendar), o de
     * toda la fila si no se indica columna.
     *
     * @return list<string>
     */
    public static function visibleKeys(DayRowPlacement $placement, ?int $column = null): array
    {
        $keys = [];

        foreach ($placement->visible as $slice) {
            if ($column === null || $slice->start == $column) {
                $keys[] = $slice->key;
            }
        }

        return $keys;
    }

    /** @return list<string> */
    public static function hiddenIds(DayCell $cell): array
    {
        return array_map(fn (Span $span): string => $span->key, $cell->hidden);
    }

    /**
     * @param  list<Span>  $spans
     * @return list<string>
     */
    public static function ids(array $spans): array
    {
        return array_map(fn (Span $span): string => $span->key, $spans);
    }
}
