<?php

use KoreUi\Calendar\Availability\ConflictFinder;
use KoreUi\Calendar\Events\EventInstance;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| Qué eventos se pisan. Mismas reglas que MutationValidator (están en Collision):
| carril (recurso), `overlap`, `display: background` y `groupId`.
*/

/** @param list<array{0: EventInstance, 1: EventInstance}> $pairs @return list<string> «1+2» por par, en el orden en que salen. */
function cfIds(array $pairs): array
{
    return array_map(fn (array $p) => $p[0]->event->id.'+'.$p[1]->event->id, $pairs);
}

it('dos eventos que se solapan en el mismo recurso son un conflicto', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a']]),
        ixInstance(2, '2026-10-14 10:30', '2026-10-14 12:00', extra: ['resourceIds' => ['a']]),
    ]);

    expect(cfIds($pairs))->toBe(['1+2']);
});

it('en recursos distintos no hay conflicto', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a']]),
        ixInstance(2, '2026-10-14 10:30', '2026-10-14 12:00', extra: ['resourceIds' => ['b']]),
    ]);

    expect($pairs)->toBe([]);
});

it('los eventos sin recurso chocan entre sí, pero no con uno que tiene recurso', function () {
    $sinRecurso = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 10:30', '2026-10-14 12:00'),
    ]);
    $mezclado = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 10:30', '2026-10-14 12:00', extra: ['resourceIds' => ['a']]),
    ]);

    expect(cfIds($sinRecurso))->toBe(['1+2'])
        ->and($mezclado)->toBe([]);
});

it('un evento con varios recursos choca con quien comparta cualquiera de ellos, una sola vez', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a', 'b']]),
        ixInstance(2, '2026-10-14 10:30', '2026-10-14 12:00', extra: ['resourceIds' => ['b', 'a']]),
        ixInstance(3, '2026-10-14 10:30', '2026-10-14 12:00', extra: ['resourceIds' => ['c']]),
    ]);

    expect(cfIds($pairs))->toBe(['1+2']);
});

it('los intervalos son semiabiertos: acabar cuando empieza otro no es chocar', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 12:00'),
        ixInstance(3, '2026-10-14 11:59', '2026-10-14 12:30'),
    ]);

    expect(cfIds($pairs))->toBe(['2+3']);
});

it('cada par sale una vez, ordenado por inicio, y los encadenados no se emparejan entre sí', function () {
    $pairs = ConflictFinder::find([
        ixInstance(3, '2026-10-14 12:00', '2026-10-14 14:00'),
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 13:00'),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 12:30'),
        ixInstance(4, '2026-10-14 15:00', '2026-10-14 16:00'),
    ]);

    // 1 choca con 2 y con 3; 2 con 3 (12:00-12:30). El 4 está solo.
    expect(cfIds($pairs))->toBe(['1+2', '1+3', '2+3']);
});

it('un evento largo choca con uno corto que empieza mucho después de su inicio', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-12', '2026-10-19'),
        ixInstance(2, '2026-10-13 10:00', '2026-10-13 11:00'),
        ixInstance(3, '2026-10-18 10:00', '2026-10-18 11:00'),
        ixInstance(4, '2026-10-20 10:00', '2026-10-20 11:00'),
    ]);

    // Un día completo y uno con hora del mismo día se solapan (como en el validador).
    expect(cfIds($pairs))->toBe(['1+2', '1+3']);
});

// ── overlap, background, groupId ───────────────────────────────────────

it('un evento de fondo no choca con nadie, salvo que diga overlap false', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00'),
        ixInstance(2, '2026-10-14 10:00', '2026-10-14 12:00', extra: ['display' => 'background']),
        ixInstance(3, '2026-10-14 11:00', '2026-10-14 13:00', extra: ['display' => 'background', 'overlap' => false]),
        ixInstance(4, '2026-10-14 10:00', '2026-10-14 12:00', extra: ['display' => 'none']),
    ]);

    // El 3 (fondo con overlap:false, «aquí no se puede») choca con el 1; el 2 y el 4 con nadie.
    expect(cfIds($pairs))->toBe(['1+3']);
});

it('con overlapAllowed solo cuentan los que declaran overlap false', function () {
    $eventos = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00'),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00'),
        ixInstance(3, '2026-10-14 11:30', '2026-10-14 12:30', extra: ['overlap' => false]),
    ];

    expect(cfIds(ConflictFinder::find($eventos)))->toBe(['1+2', '1+3', '2+3'])
        ->and(cfIds(ConflictFinder::find($eventos, overlapAllowed: true)))->toBe(['1+3', '2+3']);
});

it('overlap true no exime: la regla es la del validador, y el validador no lo hace', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00', extra: ['overlap' => true]),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00'),
    ]);

    expect(cfIds($pairs))->toBe(['1+2']);
});

it('los del mismo groupId no chocan entre sí, ni siquiera con overlap false', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00', extra: ['groupId' => 'g', 'overlap' => false]),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00', extra: ['groupId' => 'g', 'overlap' => false]),
        ixInstance(3, '2026-10-14 11:00', '2026-10-14 13:00', extra: ['groupId' => 'otro']),
    ]);

    expect(cfIds($pairs))->toBe(['1+3', '2+3']);
});

it('dos eventos sin groupId no son del mismo grupo', function () {
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00'),
        ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00'),
    ]);

    expect($pairs)->toHaveCount(1);
});

it('las ocurrencias de un mismo evento (mismo id) no chocan consigo mismas', function () {
    $pairs = ConflictFinder::find([
        ixInstance('serie', '2026-10-14 10:00', '2026-10-14 12:00', extra: ['resourceIds' => ['a']]),
        ixInstance('serie', '2026-10-14 11:00', '2026-10-14 13:00', extra: ['resourceIds' => ['a']]),
    ]);

    expect($pairs)->toBe([]);
});

// ── Instantes exactos y ayudas ─────────────────────────────────────────

it('con instantes exactos, las dos pasadas de la hora repetida del otoño no chocan', function () {
    // Mismo marker (02:10-02:40) pero instantes distintos: +02:00 y +01:00.
    $pairs = ConflictFinder::find([
        ixInstance(1, '2026-10-25T02:10:00+02:00', '2026-10-25T02:40:00+02:00'),
        ixInstance(2, '2026-10-25T02:10:00+01:00', '2026-10-25T02:40:00+01:00'),
    ]);
    // Con una hora flotante no hay instante y se compara en pared: ahí sí chocan.
    $flotante = ConflictFinder::find([
        ixInstance(1, '2026-10-25T02:10:00+02:00', '2026-10-25T02:40:00+02:00'),
        ixInstance(2, '2026-10-25 02:10', '2026-10-25 02:40'),
    ]);

    expect($pairs)->toBe([])
        ->and(cfIds($flotante))->toBe(['1+2']);
});

it('sin eventos o con uno solo no hay conflictos', function () {
    expect(ConflictFinder::find([]))->toBe([])
        ->and(ConflictFinder::find([ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')]))->toBe([]);
});

it('byInstance() da, desde cada evento, con quién choca', function () {
    $a = ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00');
    $b = ixInstance(2, '2026-10-14 11:00', '2026-10-14 13:00');
    $c = ixInstance(3, '2026-10-14 11:30', '2026-10-14 12:30');
    $solo = ixInstance(4, '2026-10-15 10:00', '2026-10-15 11:00');

    $map = ConflictFinder::byInstance(ConflictFinder::find([$a, $b, $c, $solo]));

    expect(array_keys($map))->toBe([$a->instanceId, $b->instanceId, $c->instanceId])
        ->and(array_map(fn ($i) => $i->event->id, $map[$a->instanceId]))->toBe([2, 3])
        ->and(array_map(fn ($i) => $i->event->id, $map[$c->instanceId]))->toBe([1, 2])
        ->and(isset($map[$solo->instanceId]))->toBeFalse();
});
