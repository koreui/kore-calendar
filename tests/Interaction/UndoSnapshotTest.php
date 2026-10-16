<?php

use KoreUi\Calendar\Interaction\UndoSnapshot;
use KoreUi\Calendar\Time\Marker;

require_once __DIR__.'/helpers.php';

/*
| El estado anterior de un evento, tal como se guarda entre peticiones y como vuelve
| a ser una mutación. Madrid: el domingo 25 de octubre de 2026 la hora 02:00-03:00
| ocurre dos veces.
*/

function usMove(object $instance, string $destino, bool $allDay = false, ?KoreUi\Calendar\View\CalendarSettings $settings = null, ?array $resources = null)
{
    $s = $settings ?? ixSettings();

    return ixBuilderFor($s)->move($instance, ixMarker($destino), $allDay, $resources);
}

it('guarda el estado ANTERIOR a la mutación, con solo escalares', function () {
    $mutation = usMove(ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:30'), '2026-10-15 14:00');

    $datos = UndoSnapshot::before('move', $mutation)->toArray();

    expect($datos)->toBe([
        'type' => 'move',
        'id' => 1,
        'start' => ixMarker('2026-10-14 10:00')->ts,
        'end' => ixMarker('2026-10-14 11:30')->ts,
        'allDay' => false,
        'resourceIds' => [],
        'startInstant' => $mutation->previousStart->getTimestamp(),
        'endInstant' => $mutation->previousEnd->getTimestamp(),
    ]);

    // Serializable tal cual por Livewire: nada de objetos.
    expect(json_decode(json_encode($datos), true))->toBe($datos);
});

it('va y vuelve por array sin perder nada', function () {
    $snapshot = UndoSnapshot::before('resize', usMove(ixInstance('a-1', '2026-10-14 10:00', '2026-10-14 11:30', extra: ['resourceIds' => [3, 'x']]), '2026-10-15 14:00'));

    expect(UndoSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot)
        ->and($snapshot->id)->toBe('a-1')
        ->and($snapshot->resourceIds)->toBe([3, 'x']);
});

it('un evento sin fin se guarda sin instante de fin', function () {
    $snapshot = UndoSnapshot::before('move', usMove(ixInstance(1, '2026-10-14 10:00'), '2026-10-15 14:00'));

    expect($snapshot->endInstant)->toBeNull()
        // El marker de fin sí existe: es el que la rejilla dibujaba (inicio + la duración por defecto).
        ->and($snapshot->end)->toBe(ixMarker('2026-10-14 11:00')->ts);
});

it('un estado que no se entiende es «nada que deshacer», no una excepción', function (mixed $datos) {
    expect(UndoSnapshot::fromArray($datos))->toBeNull();
})->with([
    'null' => [null],
    'texto' => ['move'],
    'vacío' => [[]],
    'tipo desconocido' => [['type' => 'delete', 'id' => 1, 'start' => 1, 'end' => 2, 'allDay' => false, 'resourceIds' => [], 'startInstant' => 1, 'endInstant' => 2]],
    'id que no es un id' => [['type' => 'move', 'id' => [1], 'start' => 1, 'end' => 2, 'allDay' => false, 'resourceIds' => [], 'startInstant' => 1, 'endInstant' => 2]],
    'marker que no es entero' => [['type' => 'move', 'id' => 1, 'start' => '1', 'end' => 2, 'allDay' => false, 'resourceIds' => [], 'startInstant' => 1, 'endInstant' => 2]],
    'allDay que no es bool' => [['type' => 'move', 'id' => 1, 'start' => 1, 'end' => 2, 'allDay' => 1, 'resourceIds' => [], 'startInstant' => 1, 'endInstant' => 2]],
    'recursos que no son lista' => [['type' => 'move', 'id' => 1, 'start' => 1, 'end' => 2, 'allDay' => false, 'resourceIds' => 5, 'startInstant' => 1, 'endInstant' => 2]],
    'instante que no es entero' => [['type' => 'move', 'id' => 1, 'start' => 1, 'end' => 2, 'allDay' => false, 'resourceIds' => [], 'startInstant' => 'ahora', 'endInstant' => 2]],
]);

it('la inversa lleva el evento actual a como estaba y deja lo de ahora en previous*', function () {
    $settings = ixSettings();
    $antes = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:30');
    $snapshot = UndoSnapshot::before('move', usMove($antes, '2026-10-15 14:00'));

    // El evento ya movido, como lo devolvería events().
    $ahora = ixInstance(1, '2026-10-15 14:00', '2026-10-15 15:30');
    $inversa = $snapshot->inverse($ahora, $settings);

    expect($inversa->event)->toBe($ahora->event)
        ->and(ixIso($inversa->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(ixIso($inversa->end))->toBe('2026-10-14T11:30:00+02:00')
        ->and(ixIso($inversa->endInstant))->toBe('2026-10-14T11:30:00+02:00')
        ->and((string) $inversa->startMarker)->toBe('2026-10-14 10:00:00')
        ->and((string) $inversa->endMarker)->toBe('2026-10-14 11:30:00')
        ->and((string) $inversa->previousStartMarker)->toBe('2026-10-15 14:00:00')
        ->and((string) $inversa->previousEndMarker)->toBe('2026-10-15 15:30:00')
        ->and(ixIso($inversa->previousStart))->toBe('2026-10-15T14:00:00+02:00')
        ->and($inversa->deltaSeconds)->toBe(-(28 * 3600))
        ->and($inversa->isNoop())->toBeFalse();
});

it('si el evento ya está como estaba, la inversa no cambia nada', function () {
    $antes = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:30');
    $snapshot = UndoSnapshot::before('move', usMove($antes, '2026-10-15 14:00'));

    expect($snapshot->inverse($antes, ixSettings())->isNoop())->toBeTrue();
});

it('el delta de un cambio de duración es el del borde que cambió', function () {
    $antes = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:30');
    $recortado = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');
    $snapshot = UndoSnapshot::before('resize', ixBuilder()->resize($antes, ixMarker('2026-10-14 11:00')));

    expect($snapshot->inverse($recortado, ixSettings())->deltaSeconds)->toBe(1800);
});

it('restaura el instante de la hora repetida del otoño, no su otra ocurrencia', function () {
    $settings = ixSettings(['date' => '2026-10-25']);
    // Empieza en la SEGUNDA 02:30 (+01:00): el marker es el mismo que el de la primera.
    $antes = ixInstance(1, '2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00');
    $snapshot = UndoSnapshot::before('move', usMove($antes, '2026-10-25 05:00', settings: $settings));

    $ahora = ixInstance(1, '2026-10-25 05:00', '2026-10-25 06:00');
    $inversa = $snapshot->inverse($ahora, $settings);

    expect(ixIso($inversa->start))->toBe('2026-10-25T02:30:00+01:00')
        // Y no '+02:00', que es lo que daría reconstruirla desde el marker.
        ->and(ixIso($settings->clock->toInstant(Marker::parse('2026-10-25 02:30'))))->toBe('2026-10-25T02:30:00+02:00');
});

it('un día completo se restaura como fechas, con su fin exclusivo', function () {
    $settings = ixSettings();
    $antes = ixInstance(2, '2026-10-15', '2026-10-17');
    $snapshot = UndoSnapshot::before('move', usMove($antes, '2026-10-15 09:00', false, $settings));

    $ahora = ixInstance(2, '2026-10-15 09:00', '2026-10-15 10:00');
    $inversa = $snapshot->inverse($ahora, $settings);

    expect($inversa->allDay)->toBeTrue()
        ->and($inversa->isAllDayChange())->toBeTrue()
        ->and($inversa->startDate())->toBe('2026-10-15')
        ->and($inversa->endDate())->toBe('2026-10-17');
});
