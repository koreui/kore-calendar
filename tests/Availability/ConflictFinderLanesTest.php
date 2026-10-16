<?php

use KoreUi\Calendar\Availability\ConflictFinder;
use KoreUi\Calendar\Events\EventInstance;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| `find()` barre cada carril por separado en vez de comparar cada evento con todos los
| siguientes. El resultado tiene que ser el de siempre: mismos pares y en el mismo orden.
| La referencia es el algoritmo anterior, copiado aquí tal cual.
*/

/** `ConflictFinder::find()` antes de barrer por carril: todos contra todos, con el corte por inicio. */
function cflReferenceFind(array $instances, bool $overlapAllowed = false): array
{
    usort($instances, fn (EventInstance $a, EventInstance $b) => [$a->range->start->ts, $a->instanceId] <=> [$b->range->start->ts, $b->instanceId]);

    $pairs = [];
    $total = count($instances);

    for ($i = 0; $i < $total; $i++) {
        $a = $instances[$i];

        for ($j = $i + 1; $j < $total; $j++) {
            $b = $instances[$j];

            if ($b->range->start->ts >= $a->range->end->ts + 86_400) {
                break;
            }

            if (ConflictFinder::conflicts($a, $b, $overlapAllowed)) {
                $pairs[] = [$a, $b];
            }
        }
    }

    return $pairs;
}

/** @return list<string> «instanceId|instanceId» por par, en orden. */
function cflIds(array $pairs): array
{
    return array_map(fn (array $p) => $p[0]->instanceId.'|'.$p[1]->instanceId, $pairs);
}

/**
 * Eventos al azar alrededor del cambio de hora de otoño (25 de octubre en Madrid): con y sin instante exacto,
 * de día completo, con varios recursos (repetidos y en otro orden), sin recurso, `overlap: false`, de fondo,
 * del mismo grupo y con ids repetidos (las ocurrencias de una serie).
 *
 * @return list<EventInstance>
 */
function cflRandom(int $seed, int $count): array
{
    mt_srand($seed);
    $resources = [[], ['a'], ['b'], ['a', 'b'], ['b', 'a'], ['a', 'a'], ['c', 'b', 'a'], [1], ['1'], [2, 'a']];
    $instances = [];

    for ($n = 0; $n < $count; $n++) {
        $day = mt_rand(23, 28);
        $extra = ['resourceIds' => $resources[mt_rand(0, count($resources) - 1)]];

        if (mt_rand(1, 10) === 1) {
            $extra['overlap'] = false;
        }
        if (mt_rand(1, 12) === 1) {
            $extra['display'] = ['background', 'none'][mt_rand(0, 1)];
        }
        if (mt_rand(1, 8) === 1) {
            $extra['groupId'] = 'g'.mt_rand(1, 2);
        }

        $id = mt_rand(1, 6) === 1 ? 'serie' : $n;

        if (mt_rand(1, 8) === 1) {
            $instances[] = ixInstance($id, sprintf('2026-10-%02d', $day), sprintf('2026-10-%02d', $day + mt_rand(1, 3)), true, $extra);

            continue;
        }

        $start = mktime(mt_rand(0, 23), mt_rand(0, 3) * 15, 0, 10, $day, 2026);
        $end = $start + mt_rand(1, 130) * 900;
        $format = mt_rand(0, 1) ? 'Y-m-d H:i' : 'Y-m-d\TH:i:sP';
        $instances[] = ixInstance($id, date($format, $start), date($format, $end), false, $extra);
    }

    // El mismo objeto dos veces (dos fuentes que devuelven el mismo evento).
    if ($instances !== []) {
        $instances[] = $instances[mt_rand(0, count($instances) - 1)];
    }

    return $instances;
}

it('barrer por carril da los mismos pares y en el mismo orden que el todos-contra-todos (semillas 1-60)', function () {
    for ($seed = 1; $seed <= 60; $seed++) {
        $instances = cflRandom($seed, 40);

        foreach ([false, true] as $overlapAllowed) {
            expect(cflIds(ConflictFinder::find($instances, $overlapAllowed)))
                ->toBe(cflIds(cflReferenceFind($instances, $overlapAllowed)), "semilla {$seed}, overlapAllowed ".var_export($overlapAllowed, true));
        }
    }
});

it('el resultado no depende del orden en que llegan los eventos', function () {
    $instances = cflRandom(7, 60);
    $esperado = cflIds(ConflictFinder::find($instances));

    mt_srand(99);
    shuffle($instances);

    expect(cflIds(ConflictFinder::find($instances)))->toBe($esperado)
        ->and($esperado)->not->toBe([]);
});

it('con $within salen todos los pares de esas instancias y en el mismo orden, sin barrer el resto', function () {
    for ($seed = 1; $seed <= 40; $seed++) {
        $instances = cflRandom($seed, 40);
        mt_srand($seed);
        $within = array_values(array_filter($instances, fn () => mt_rand(1, 4) === 1));
        $ids = array_flip(array_map(fn (EventInstance $i) => $i->instanceId, $within));

        $esperado = array_values(array_filter(
            cflReferenceFind($instances),
            fn (array $p) => isset($ids[$p[0]->instanceId]) || isset($ids[$p[1]->instanceId]),
        ));

        expect(cflIds(ConflictFinder::find($instances, false, $within)))->toBe(cflIds($esperado), "semilla {$seed}");
    }
});

it('$within vacío no busca nada, y con todos los eventos equivale a no pasarlo', function () {
    $instances = cflRandom(3, 40);

    expect(ConflictFinder::find($instances, false, []))->toBe([])
        ->and(cflIds(ConflictFinder::find($instances, false, $instances)))->toBe(cflIds(ConflictFinder::find($instances)));
});

it('un evento de $within con un recurso que no se ve choca con quien comparte ese recurso', function () {
    // El 1 está en `a` y `b`; el 2 solo en `b`; el 3 solo en `a`. Se pintan el 1 y el 3: el 1 sigue chocando con el 2.
    $uno = ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00', extra: ['resourceIds' => ['a', 'b']]);
    $dos = ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00', extra: ['resourceIds' => ['b']]);
    $tres = ixInstance(3, '2026-10-14 15:00', '2026-10-14 16:00', extra: ['resourceIds' => ['a']]);
    $cuatro = ixInstance(4, '2026-10-14 15:30', '2026-10-14 16:30', extra: ['resourceIds' => ['c']]);
    $cinco = ixInstance(5, '2026-10-14 15:45', '2026-10-14 16:15', extra: ['resourceIds' => ['c']]);

    $pairs = ConflictFinder::find([$uno, $dos, $tres, $cuatro, $cinco], false, [$uno, $tres]);

    // El 4 y el 5 chocan, pero ninguno se pinta y su carril (`c`) no es de ninguno que se pinte.
    expect(cflIds($pairs))->toBe([$uno->instanceId.'|'.$dos->instanceId]);
});
