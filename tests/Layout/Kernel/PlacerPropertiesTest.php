<?php

/*
 * Pruebas de propiedades de los dos adaptadores, con semillas 1..300 y el
 * generador determinista de kernel-fuzz.test.ts de FullCalendar v7.1.3 (MIT; ver
 * THIRD-PARTY-NOTICES.md). Las invariantes salen de docs/calendar.md §16 y de
 * fc-edge-cases §9; el original solo las prueba sobre el kernel y, en la
 * rejilla horaria, en el audit de timegrid-adapter.test.ts. Aquí se comprueban
 * sobre lo que de verdad recibe Blade.
 */

use KoreUi\Calendar\Layout\Kernel\DayRowPlacement;
use KoreUi\Calendar\Layout\Kernel\DayRowPlacer;
use KoreUi\Calendar\Layout\Kernel\PlacedEvent;
use KoreUi\Calendar\Layout\Kernel\Span;
use KoreUi\Calendar\Layout\Kernel\TimeColumnPlacement;
use KoreUi\Calendar\Layout\Kernel\TimeColumnPlacer;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

/** Una fila de la vista mes con 7 columnas; algunos spans recortados por la izquierda o la derecha. */
function propertyRowSpans(int $seed): array
{
    $random = Fixtures::createRandom($seed * 31_337);
    $spans = [];

    foreach (Fixtures::buildScenario($seed) as $source) {
        $spans[] = new Span("e{$source->orderIndex}", $source->start, $source->end, null, $random() > 0.3, $random() > 0.3);
    }

    return $spans;
}

/** @return array{0: ?int, 1: ?int, 2: bool, 3: bool} [maxEvents, maxEventRows, strict, slicing] */
function propertyRowOptions(int $seed): array
{
    return match ($seed % 3) {
        0 => [null, null, $seed % 2 === 0, $seed % 5 !== 0],
        1 => [1 + $seed % 4, null, $seed % 2 === 0, $seed % 5 !== 0],
        default => [null, 1 + $seed % 5, $seed % 2 === 0, $seed % 5 !== 0],
    };
}

function propertyProjectRow(DayRowPlacement $placement): string
{
    return json_encode([
        array_map(fn ($s) => [$s->key, $s->level, $s->row, $s->isStart, $s->isEnd, $s->isSlice, $s->start, $s->end], $placement->visible),
        array_map(fn ($c) => [Fixtures::ids($c->hidden), Fixtures::ids($c->all), $c->usedRows], $placement->cells),
    ]);
}

it('vista mes: sin solapes, partición visible+oculto, un enlace por celda y nada desaparece (semillas 1-300)', function () {
    for ($seed = 1; $seed <= 300; $seed++) {
        $spans = propertyRowSpans($seed);
        [$maxEvents, $maxRows, $strict, $slicing] = propertyRowOptions($seed);
        $label = json_encode(compact('seed', 'maxEvents', 'maxRows', 'strict', 'slicing'));
        $placement = DayRowPlacer::place($spans, 7, $maxEvents, $maxRows, $strict, $slicing);

        $orderOf = [];
        foreach ($spans as $index => $span) {
            $orderOf[$span->key] = $index;
        }

        // 1. Claves únicas, sin solapes en un nivel ni en una fila, y orden del DOM.
        $keys = array_map(fn ($s) => $s->key, $placement->visible);
        Fixtures::invariant(count($keys) === count(array_unique($keys)), 'claves de trozos repetidas', $label);

        $previous = null;
        foreach ($placement->visible as $slice) {
            Fixtures::invariant($slice->row >= 0 && $slice->row < max(1, $placement->rowCount), 'fila fuera de rango', $label);
            if ($previous !== null) {
                $inOrder = $previous->start < $slice->start
                    || ($previous->start == $slice->start && $orderOf[$previous->span->key] <= $orderOf[$slice->span->key]);
                Fixtures::invariant($inOrder, 'el DOM no va por columna y orden de evento', $label);
            }
            $previous = $slice;
        }

        foreach ($placement->visible as $i => $a) {
            foreach (array_slice($placement->visible, $i + 1) as $b) {
                if (! Fixtures::intersects($a, $b)) {
                    continue;
                }
                Fixtures::invariant($a->row !== $b->row, 'dos trozos visibles se pintan uno encima de otro', $label);
                Fixtures::invariant($a->level !== $b->level, 'dos trozos que se intersecan comparten nivel', $label);

                // 2. Orden estricto: el de menor orden queda más arriba.
                if ($strict && $a->span !== $b->span) {
                    [$first, $second] = $orderOf[$a->span->key] < $orderOf[$b->span->key] ? [$a, $b] : [$b, $a];
                    Fixtures::invariant($first->row < $second->row, 'el orden de evento estricto se invirtió en las filas', $label);
                }
            }
        }

        // 3. Cada fuente es la unión disjunta de sus trozos visibles y ocultos, con los flags correctos.
        foreach ($spans as $span) {
            $pieces = [];
            foreach ($placement->visible as $slice) {
                if ($slice->span === $span) {
                    $pieces[] = [$slice->start, $slice->end, $slice->isStart, $slice->isEnd];
                }
            }
            foreach ($placement->hiddenSlices as $slice) {
                if ($slice->source->payload === $span) {
                    $pieces[] = [$slice->start, $slice->end, $slice->isStart, $slice->isEnd];
                }
            }
            usort($pieces, fn ($x, $y) => $x[0] <=> $y[0]);

            $cursor = $span->start;
            foreach ($pieces as [$start, $end, $isStart, $isEnd]) {
                Fixtures::invariant($start == $cursor && $end > $start, "{$span->key} tiene un hueco, un solape o un trozo vacío", $label);
                Fixtures::invariant($isStart === ($span->isStart && $start == $span->start), "{$span->key} tiene mal isStart", $label);
                Fixtures::invariant($isEnd === ($span->isEnd && $end == $span->end), "{$span->key} tiene mal isEnd", $label);
                $cursor = $end;
            }
            Fixtures::invariant($cursor == $span->end, "{$span->key} no queda cubierto del todo", $label);
        }

        // 4. Por columna: la celda y el popover son coherentes con la partición y nada desaparece.
        for ($column = 0; $column < 7; $column++) {
            $cell = $placement->cells[$column];
            $crossing = array_values(array_filter($spans, fn (Span $s) => $s->start <= $column && $column < $s->end));

            Fixtures::invariant($cell->all === $crossing, "la lista del popover de la columna {$column} no son todas las que la cruzan", $label);

            $hiddenOrders = array_map(fn (Span $s) => $orderOf[$s->key], $cell->hidden);
            $sorted = $hiddenOrders;
            sort($sorted);
            Fixtures::invariant($hiddenOrders === array_values(array_unique($sorted)) && $hiddenOrders === $sorted, 'ocultos repetidos o fuera de orden de evento', $label);
            Fixtures::invariant($cell->hiddenCount() === count($cell->hidden), 'hiddenCount no coincide', $label);

            foreach ($crossing as $span) {
                $isVisibleHere = false;
                foreach ($placement->visible as $slice) {
                    $isVisibleHere = $isVisibleHere || ($slice->span === $span && $slice->start <= $column && $column < $slice->end);
                }
                $isHiddenHere = in_array($span, $cell->hidden, true);

                Fixtures::invariant($isVisibleHere xor $isHiddenHere, "{$span->key} desaparece o aparece dos veces en la columna {$column}", $label);
            }

            foreach ($cell->hidden as $span) {
                Fixtures::invariant(in_array($span, $crossing, true), 'un oculto no cruza la columna de su celda', $label);
            }

            // 5. Límites: nada pasa del tope y, con tasa, el enlace deja libre la última fila.
            $limit = $maxEvents ?? $maxRows;
            if ($limit !== null) {
                Fixtures::invariant($cell->usedRows <= $limit, 'una celda pasa del límite', $label);
                if ($maxEvents === null && $cell->hiddenCount() > 0) {
                    Fixtures::invariant($cell->usedRows <= $limit - 1, 'el enlace no dejó libre su fila (tasa)', $label);
                }
            } else {
                Fixtures::invariant($cell->hiddenCount() === 0, 'sin límite no se oculta nada', $label);
            }
        }

        // 6. Determinismo.
        Fixtures::invariant(
            propertyProjectRow($placement) === propertyProjectRow(DayRowPlacer::place($spans, 7, $maxEvents, $maxRows, $strict, $slicing)),
            'el resultado no es determinista',
            $label,
        );
    }
});

/** Spans de una columna horaria: media docena en rejilla de 15 min (semillas pares) o con coma flotante (impares). */
function propertyTimeSpans(int $seed): array
{
    $random = Fixtures::createRandom($seed * 92_821);
    $count = 3 + (int) floor($random() * 18);
    $spans = [];

    for ($i = 0; $i < $count; $i++) {
        if ($seed % 2 === 0) {
            $start = (int) floor($random() * 90) * 15;
            $end = $start + (1 + (int) floor($random() * 8)) * 15;
        } else {
            $start = $random() * 1300;
            $end = $start + 5 + $random() * 140;
        }
        $spans[] = new Span("t{$i}", $start, $end);
    }

    // `eventOrder` por defecto: inicio, duración descendente (estable).
    usort($spans, fn (Span $a, Span $b) => ($a->start <=> $b->start) ?: (($b->end - $b->start) <=> ($a->end - $a->start)));

    return $spans;
}

function propertyProjectTime(TimeColumnPlacement $placement): string
{
    return json_encode([
        array_map(fn (PlacedEvent $p) => [$p->span->key, $p->level, $p->levelCoord, $p->thickness, $p->backwardDepth, $p->forwardDepth, $p->left, $p->right, $p->zIndex, $p->marginEnd], $placement->placed),
        array_map(fn ($g) => [$g->start, $g->end, $g->key, Fixtures::ids($g->spans)], $placement->hidden),
    ]);
}

it('rejilla horaria: geometría válida, solapados separados, partición y grupos disjuntos (semillas 1-300)', function () {
    $eps = PHP_FLOAT_EPSILON * 8;

    for ($seed = 1; $seed <= 300; $seed++) {
        $spans = propertyTimeSpans($seed);
        $strict = $seed % 3 === 0;
        $maxStack = [null, 1, 2, 3, null][$seed % 5];
        $overlap = $seed % 4 < 2;
        $label = json_encode(compact('seed', 'strict', 'maxStack', 'overlap'));
        $placement = TimeColumnPlacer::place($spans, $strict, $maxStack, $overlap);

        $orderOf = [];
        foreach ($spans as $index => $span) {
            $orderOf[$span->key] = $index;
        }

        // 1. Geometría: 0 <= coord, thickness > 0, coord + thickness <= 1 (+ épsilon), right >= 0.
        foreach ($placement->placed as $p) {
            Fixtures::invariant($p->levelCoord >= 0 && $p->thickness > 0, "{$p->span->key}: coordenada o grosor inválidos", $label);
            Fixtures::invariant($p->levelCoord + $p->thickness <= 1 + $eps, "{$p->span->key}: se sale de la columna", $label);
            Fixtures::invariant($p->left == $p->levelCoord && $p->right >= 0 && $p->right <= 1, "{$p->span->key}: left/right fuera de rango", $label);
            Fixtures::invariant($p->zIndex === $p->backwardDepth + 1, 'z-index distinto de backwardDepth + 1', $label);
            Fixtures::invariant($p->marginEnd === ($overlap && $p->forwardDepth > 0), 'marginEnd mal derivado', $label);

            $far = $p->levelCoord + $p->thickness;
            $expectedFar = $overlap ? min(1.0, $p->levelCoord + ($far - $p->levelCoord) * 2) : $far;
            Fixtures::invariant(abs((1 - $p->right) - min(1.0, $expectedFar)) <= $eps, 'right no sigue computeSegHStyle', $label);
        }

        // 2. Dos eventos solapados en el tiempo no se superponen en horizontal (rectángulo base).
        foreach ($placement->placed as $i => $a) {
            $overlapsAny = false;

            foreach (array_slice($placement->placed, $i + 1) as $b) {
                if (! ($a->span->start < $b->span->end && $b->span->start < $a->span->end)) {
                    continue;
                }
                $overlapsAny = true;

                Fixtures::invariant(
                    $a->levelCoord + $a->thickness <= $b->levelCoord + $eps || $b->levelCoord + $b->thickness <= $a->levelCoord + $eps,
                    "{$a->span->key} y {$b->span->key} se superponen en horizontal",
                    $label,
                );

                // 3. Estricto: el de menor orden queda más a la izquierda.
                if ($strict) {
                    [$first, $second] = $orderOf[$a->span->key] < $orderOf[$b->span->key] ? [$a, $b] : [$b, $a];
                    Fixtures::invariant($first->levelCoord < $second->levelCoord, 'el orden de evento estricto se invirtió', $label);
                }
            }

            foreach (array_slice($placement->placed, 0, $i) as $b) {
                $overlapsAny = $overlapsAny || ($a->span->start < $b->span->end && $b->span->start < $a->span->end);
            }

            // Un evento que no choca con ningún otro ocupa toda la columna.
            if (! $overlapsAny) {
                Fixtures::invariant($a->levelCoord == 0 && $a->thickness == 1, "{$a->span->key} está solo y no ocupa la columna entera", $label);
            }
        }

        // 4. Orden del DOM: inicio y luego orden de evento.
        for ($i = 1, $n = count($placement->placed); $i < $n; $i++) {
            $x = $placement->placed[$i - 1]->span;
            $y = $placement->placed[$i]->span;
            Fixtures::invariant($x->start < $y->start || ($x->start == $y->start && $orderOf[$x->key] < $orderOf[$y->key]), 'orden del DOM incorrecto', $label);
        }

        // 5. Partición: cada span está colocado o en exactamente un grupo; ninguno se pierde ni se repite.
        $accounted = array_map(fn (PlacedEvent $p) => $p->span->key, $placement->placed);
        foreach ($placement->hidden as $group) {
            foreach ($group->spans as $span) {
                $accounted[] = $span->key;
            }
        }
        sort($accounted);
        $expected = array_map(fn (Span $s) => $s->key, $spans);
        sort($expected);
        Fixtures::invariant($accounted === $expected, 'un evento desapareció o aparece dos veces', $label);

        // 6. Grupos ocultos: disjuntos, ordenados, no vacíos, en orden de evento, con la clave del primero.
        $previousEnd = null;
        foreach ($placement->hidden as $group) {
            Fixtures::invariant($group->spans !== [] && $group->start < $group->end, 'grupo vacío', $label);
            Fixtures::invariant($previousEnd === null || $group->start >= $previousEnd, 'grupos ocultos que se intersecan u desordenados', $label);
            $previousEnd = $group->end;

            $orders = array_map(fn (Span $s) => $orderOf[$s->key], $group->spans);
            $sorted = $orders;
            sort($sorted);
            Fixtures::invariant($orders === $sorted, 'miembros del grupo fuera de orden de evento', $label);
            Fixtures::invariant($group->key === $group->spans[0]->key, 'la clave del grupo no sale del primer evento', $label);

            foreach ($group->spans as $span) {
                Fixtures::invariant($span->start >= $group->start && $span->end <= $group->end, 'un evento se sale de su grupo', $label);
            }
        }

        // 7. El tope de pila se respeta y, sin tope, nada se oculta.
        if ($maxStack === null) {
            Fixtures::invariant($placement->hidden === [], 'sin eventMaxStack no se oculta nada', $label);
        } else {
            foreach ($placement->placed as $p) {
                Fixtures::invariant($p->level < $maxStack, 'un evento pasa del eventMaxStack', $label);
            }
        }

        // 8. Determinismo.
        Fixtures::invariant(
            propertyProjectTime($placement) === propertyProjectTime(TimeColumnPlacer::place($spans, $strict, $maxStack, $overlap)),
            'el resultado no es determinista',
            $label,
        );
    }
});
