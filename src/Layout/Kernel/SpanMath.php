<?php

namespace KoreUi\Calendar\Layout\Kernel;

/**
 * Geometría lateral pura que comparte todo el motor de colocación.
 *
 * Port de `packages/preact/src/seg-placement/span-math.ts` (FullCalendar v7.1.3,
 * MIT; ver THIRD-PARTY-NOTICES.md).
 *
 * Un tramo es un intervalo semiabierto `[start, end)` sobre el eje lateral. El
 * eje puede ser discreto (columnas de la vista mes) o continuo (segundos de una
 * columna horaria). Dos tramos que solo se tocan en el borde NO se intersecan:
 * por eso un evento que acaba a las 10:00 y otro que empieza a las 10:00 caben
 * en el mismo nivel.
 *
 * Las funciones aceptan cualquier objeto con `start` y `end` públicos (un
 * `Slice`, un `Source`, un `Lateral`...). Es el mismo duck typing de TypeScript
 * y evita envolver cada cosa en un adaptador. Las comparaciones usan `<`, `<=`,
 * `==` sobre números: en PHP `1 === 1.0` es falso, así que NUNCA se compara con
 * `===` un `int|float`.
 *
 * @internal
 */
final class SpanMath
{
    private function __construct() {}

    public static function intersects(object $a, object $b): bool
    {
        return $a->start < $b->end && $b->start < $a->end;
    }

    /** La intersección estricta; dos tramos exactamente adyacentes no se intersecan. */
    public static function intersect(object $a, object $b): ?Lateral
    {
        $start = max($a->start, $b->start);
        $end = min($a->end, $b->end);

        return $start < $end ? new Lateral($start, $end) : null;
    }

    public static function length(object $span): int|float
    {
        return $span->end - $span->start;
    }

    /**
     * Todas las intersecciones dentro de una lista ordenada por `start`.
     *
     * Si además las entradas no se intersecan entre sí (un nivel, un conjunto de
     * reservas), solo la entrada anterior al límite inferior puede cruzar el
     * inicio del tramo, así que el barrido arranca una posición antes y corta en
     * cuanto una entrada empieza después del final: O(log n + k) en vez de O(n).
     *
     * @template T of object
     *
     * @param  list<T>  $entries
     * @return list<T>
     */
    public static function findIntersections(array $entries, object $span): array
    {
        $index = self::lowerBoundByStart($entries, $span->start);
        if ($index > 0) {
            $index--;
        }

        $matches = [];
        for ($count = count($entries); $index < $count; $index++) {
            $entry = $entries[$index];
            if ($entry->start >= $span->end) {
                break;
            }
            if (self::intersects($entry, $span)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * La diferencia de conjuntos `span - covered`: lo que queda de `span` sin
     * los tramos cubiertos, que pueden ser varios trozos disjuntos.
     *
     * `covered` tiene que venir ordenado por `start` y sin solapes internos. Todos
     * los llamadores ya lo cumplen (tramos de un plan, bloqueadores ya unidos, un
     * único tramo), por eso no se vuelve a ordenar aquí.
     *
     * @param  list<object>  $covered
     * @return list<Lateral>
     */
    public static function subtractCovered(object $span, array $covered): array
    {
        $result = [];
        $cursor = $span->start;

        foreach ($covered as $item) {
            if ($item->end <= $cursor) {
                continue;
            }
            if ($item->start >= $span->end) {
                break;
            }
            if ($item->start > $cursor) {
                $result[] = new Lateral($cursor, min($item->start, $span->end));
            }
            $cursor = max($cursor, $item->end);
            if ($cursor >= $span->end) {
                break;
            }
        }

        if ($cursor < $span->end) {
            $result[] = new Lateral($cursor, $span->end);
        }

        return $result;
    }

    /**
     * Añade un tramo a una unión ordenada de solapes estrictos. Los adyacentes se
     * quedan separados, como en los grupos de "+N más": cada grupo es un enlace
     * independiente. Reemplaza la lista y nunca modifica a sus miembros, así
     * que se puede mezclar un nivel real con reservas sin tocarlo.
     *
     * @param  list<object>  $spans
     */
    public static function addToUnion(array &$spans, object $addition): void
    {
        $result = [];
        $pending = new Lateral($addition->start, $addition->end);
        $inserted = false;

        foreach ($spans as $span) {
            if ($span->end <= $pending->start) {
                $result[] = $span;
            } elseif ($pending->end <= $span->start) {
                if (! $inserted) {
                    $result[] = $pending;
                    $inserted = true;
                }
                $result[] = $span;
            } else {
                $pending = new Lateral(
                    min($pending->start, $span->start),
                    max($pending->end, $span->end),
                );
            }
        }

        if (! $inserted) {
            $result[] = $pending;
        }

        $spans = $result;
    }

    /**
     * Inserta manteniendo el orden creciente de `start`. Ante un empate la
     * entrada nueva va ANTES de las que ya tienen ese inicio (límite inferior),
     * igual que `insertLaterally` de FullCalendar: el orden dentro de un nivel
     * es parte del resultado observable.
     *
     * @param  list<object>  $entries
     */
    public static function insertLaterally(array &$entries, object $entry): void
    {
        array_splice($entries, self::lowerBoundByStart($entries, $entry->start), 0, [$entry]);
    }

    /**
     * Primer índice cuyo `start` no es menor que el dado (búsqueda binaria).
     *
     * @param  list<object>  $entries
     */
    public static function lowerBoundByStart(array $entries, int|float $start): int
    {
        $low = 0;
        $high = count($entries);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($entries[$middle]->start < $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
