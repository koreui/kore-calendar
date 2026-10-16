<?php

use KoreUi\Calendar\Availability\AvailabilityFinder;
use KoreUi\Calendar\Availability\BusinessHours;
use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| Huecos libres dentro del horario laboral. La semana: lunes 12 a domingo 18 de octubre de 2026.
*/

function afRange(string $from, string $to): DateRange
{
    return new DateRange(Marker::parse($from), Marker::parse($to));
}

/** @param list<DateRange> $slots @return list<string> «09:00-10:00» (o con fecha si cambia el día). */
function afShow(array $slots): array
{
    return array_map(function (DateRange $r) {
        $same = $r->start->toDateString() === $r->end->toDateString() || $r->end->isStartOfDay();

        return $same
            ? substr($r->start->toDateTimeString(), 11, 5).'-'.($r->end->isStartOfDay() ? '24:00' : substr($r->end->toDateTimeString(), 11, 5))
            : $r->start->toDateTimeString().' → '.$r->end->toDateTimeString();
    }, $slots);
}

beforeEach(fn () => $this->horas = new BusinessHours(true));

it('devuelve los huecos entre eventos, dentro del horario laboral', function () {
    $eventos = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 13:00', '2026-10-14 14:30'),
    ];

    $huecos = AvailabilityFinder::freeSlots($eventos, $this->horas, afRange('2026-10-14', '2026-10-15'), 3600);

    expect(afShow($huecos))->toBe(['09:00-10:00', '11:00-13:00', '14:30-17:00']);
});

it('una duración que no cabe en ningún hueco no devuelve nada, y la que cabe justa sí', function () {
    $eventos = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 13:00', '2026-10-14 14:30'),
    ];
    $dia = afRange('2026-10-14', '2026-10-15');

    // Los huecos son 09:00-10:00 (1 h), 11:00-13:00 (2 h) y 14:30-17:00 (2 h 30).
    expect(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 2 * 3600)))->toBe(['11:00-13:00', '14:30-17:00'])
        ->and(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 2 * 3600 + 1800)))->toBe(['14:30-17:00'])
        ->and(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 2 * 3600 + 1801))->toBe([])
        ->and(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 3 * 3600))->toBe([]);
});

it('alinea el inicio de cada hueco al paso, hacia delante', function () {
    $eventos = [ixInstance(1, '2026-10-14 09:00', '2026-10-14 10:10')];

    $huecos = AvailabilityFinder::freeSlots($eventos, $this->horas, afRange('2026-10-14', '2026-10-15'), 1800, snapSeconds: 900);

    // El evento acaba a las 10:10: el primer inicio válido con paso de 15 min es las 10:15.
    expect(afShow($huecos))->toBe(['10:15-17:00']);
});

it('el paso puede comerse un hueco que sin él cabría', function () {
    // Libre de 10:10 a 10:50 (40 min). Con paso de 30 min el inicio pasa a las 10:30 y quedan 20.
    $eventos = [
        ixInstance(1, '2026-10-14 09:00', '2026-10-14 10:10'),
        ixInstance(2, '2026-10-14 10:50', '2026-10-14 17:00'),
    ];
    $dia = afRange('2026-10-14', '2026-10-15');

    expect(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 1800)))->toBe(['10:10-10:50'])
        ->and(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 1800, snapSeconds: 1800))->toBe([])
        ->and(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 1200, snapSeconds: 1800)))->toBe(['10:30-10:50']);
});

it('los días sin horario no dan huecos y el fin de semana tampoco', function () {
    $huecos = AvailabilityFinder::freeSlots([], $this->horas, afRange('2026-10-16', '2026-10-20'), 3600);

    expect(afShow($huecos))->toBe(['09:00-17:00', '09:00-17:00'])
        ->and(array_map(fn (DateRange $r) => $r->start->toDateString(), $huecos))->toBe(['2026-10-16', '2026-10-19']);
});

it('sin horario definido todo el rango es un hueco, menos lo ocupado', function () {
    $eventos = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    $huecos = AvailabilityFinder::freeSlots($eventos, new BusinessHours(false), afRange('2026-10-14', '2026-10-15'), 3600);

    expect(afShow($huecos))->toBe(['00:00-10:00', '11:00-24:00']);
});

it('solo cuentan los eventos del recurso pedido; sin recurso, cualquier evento ocupa', function () {
    $eventos = [
        ixInstance(1, '2026-10-14 09:00', '2026-10-14 12:00', extra: ['resourceIds' => ['a']]),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['resourceIds' => ['b']]),
        ixInstance(3, '2026-10-14 16:00', '2026-10-14 17:00'),   // sin recurso
    ];
    $dia = afRange('2026-10-14', '2026-10-15');

    expect(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 3600, 'a')))->toBe(['12:00-17:00'])
        ->and(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 3600, 'b')))->toBe(['09:00-14:00', '15:00-17:00'])
        // Un id numérico y su string son el mismo recurso.
        ->and(afShow(AvailabilityFinder::freeSlots([ixInstance(9, '2026-10-14 09:00', '2026-10-14 10:00', extra: ['resourceIds' => [7]])], $this->horas, $dia, 3600, '7')))->toBe(['10:00-17:00'])
        ->and(afShow(AvailabilityFinder::freeSlots($eventos, $this->horas, $dia, 3600)))->toBe(['12:00-14:00', '15:00-16:00']);
});

it('un evento de fondo no ocupa, salvo que diga overlap false; uno de día completo ocupa el día', function () {
    $eventos = [
        ixInstance(1, '2026-10-14 09:00', '2026-10-14 10:00', extra: ['display' => 'background']),
        ixInstance(2, '2026-10-14 12:00', '2026-10-14 13:00', extra: ['display' => 'background', 'overlap' => false]),
        ixInstance(3, '2026-10-15', '2026-10-16', extra: ['title' => 'Vacaciones']),
    ];

    $huecos = AvailabilityFinder::freeSlots($eventos, $this->horas, afRange('2026-10-14', '2026-10-17'), 3600);

    expect(array_map(fn (DateRange $r) => $r->start->toDateString().' '.afShow([$r])[0], $huecos))->toBe([
        '2026-10-14 09:00-12:00',
        '2026-10-14 13:00-17:00',
        '2026-10-16 09:00-17:00',
    ]);
});

it('los eventos fuera del rango se ignoran y los que lo cruzan se recortan', function () {
    $eventos = [
        ixInstance(1, '2026-10-13 15:00', '2026-10-14 09:30'),
        ixInstance(2, '2026-10-14 16:30', '2026-10-15 10:00'),
        ixInstance(3, '2026-10-20 09:00', '2026-10-20 10:00'),
    ];

    $huecos = AvailabilityFinder::freeSlots($eventos, $this->horas, afRange('2026-10-14', '2026-10-15'), 1800);

    expect(afShow($huecos))->toBe(['09:30-16:30']);
});

it('un turno de noche da huecos a los dos lados de la medianoche', function () {
    $noche = new BusinessHours(['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']);
    $eventos = [ixInstance(1, '2026-10-17 00:00', '2026-10-17 01:00')];

    $huecos = AvailabilityFinder::freeSlots($eventos, $noche, afRange('2026-10-16', '2026-10-18'), 1800);

    expect(afShow($huecos))->toBe(['22:00-24:00', '01:00-02:00']);
});

it('usa el horario del recurso con BusinessHours::forResource()', function () {
    $sala = CalendarResource::make('a', 'Sala', businessHours: ['startTime' => '08:00', 'endTime' => '12:00']);
    $horas = BusinessHours::forResource($sala, $this->horas);

    $huecos = AvailabilityFinder::freeSlots([], $horas, afRange('2026-10-14', '2026-10-15'), 3600, 'a');

    expect(afShow($huecos))->toBe(['08:00-12:00']);
});

it('el horario es de pared: el día del cambio de otoño sigue siendo de 09:00 a 17:00', function () {
    $todos = new BusinessHours(['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6]]);

    $huecos = AvailabilityFinder::freeSlots([], $todos, afRange('2026-10-25', '2026-10-26'), 3600);

    expect(afShow($huecos))->toBe(['09:00-17:00']);
});

it('rechaza una duración o un paso imposibles', function () {
    $dia = afRange('2026-10-14', '2026-10-15');

    expect(fn () => AvailabilityFinder::freeSlots([], $this->horas, $dia, 0))->toThrow(InvalidArgumentException::class, 'La duración del hueco')
        ->and(fn () => AvailabilityFinder::freeSlots([], $this->horas, $dia, 60, snapSeconds: 0))->toThrow(InvalidArgumentException::class, 'El paso del hueco');
});

it('el primer hueco es el «siguiente disponible»', function () {
    $eventos = [ixInstance(1, '2026-10-16 09:00', '2026-10-16 17:00')];

    $huecos = AvailabilityFinder::freeSlots($eventos, $this->horas, afRange('2026-10-16', '2026-10-23'), 3600);

    expect($huecos[0]->start->toDateTimeString())->toBe('2026-10-19 09:00:00');
});
