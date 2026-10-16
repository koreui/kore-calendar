<?php

use KoreUi\Calendar\Availability\BusinessHours;
use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/*
| El horario laboral en horas de pared. La semana de los tests es la del lunes 12
| al domingo 18 de octubre de 2026; el cambio de horario de otoño de Madrid es
| el domingo 25 (ese día mide 25 h, y el horario no se entera).
*/

function bhRange(string $from, string $to): DateRange
{
    return new DateRange(Marker::parse($from), Marker::parse($to));
}

/** @return list<string> Los intervalos como «inicio → fin». */
function bhShow(array $ranges): array
{
    return array_map(fn (DateRange $r) => $r->start->toDateTimeString().' → '.$r->end->toDateTimeString(), $ranges);
}

// ── Construcción ───────────────────────────────────────────────────────

it('true es lunes a viernes de 09:00 a 17:00, como en FullCalendar', function () {
    expect((new BusinessHours(true))->toArray())->toBe([['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '09:00', 'endTime' => '17:00']]);
});

it('false, null y una lista vacía no definen horario', function (mixed $input) {
    $hours = new BusinessHours($input);

    expect($hours->isEmpty())->toBeTrue()
        ->and($hours->toArray())->toBe([]);
})->with(['false' => [false], 'null' => [null], 'lista vacía' => [[]]]);

it('un tramo rellena lo que falta con los valores de FullCalendar', function () {
    expect((new BusinessHours(['startTime' => '08:00']))->toArray())->toBe([['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '17:00']])
        ->and((new BusinessHours(['daysOfWeek' => [3, 1, 3], 'endTime' => '14:00']))->toArray())->toBe([['daysOfWeek' => [1, 3], 'startTime' => '09:00', 'endTime' => '14:00']]);
});

it('una lista de tramos conserva cada uno', function () {
    $hours = new BusinessHours([
        ['daysOfWeek' => [1, 2], 'startTime' => '09:00', 'endTime' => '13:00'],
        ['daysOfWeek' => [1, 2], 'startTime' => '15:00', 'endTime' => '19:00'],
    ]);

    expect($hours->toArray())->toHaveCount(2)
        ->and($hours->isEmpty())->toBeFalse();
});

it('from() acepta una instancia tal cual y falla en español con un tipo imposible', function () {
    $hours = new BusinessHours(true);

    expect(BusinessHours::from($hours))->toBe($hours)
        ->and(BusinessHours::from(true)->toArray())->toBe($hours->toArray())
        ->and(fn () => BusinessHours::from('lunes a viernes'))->toThrow(InvalidArgumentException::class, 'Horario laboral: se esperaba true, false, null, un tramo o una lista de tramos, y llegó string');
});

it('la validación es estricta y los mensajes explican qué corregir', function (mixed $input, string $mensaje) {
    expect(fn () => new BusinessHours($input))->toThrow(InvalidArgumentException::class, $mensaje);
})->with([
    'clave desconocida' => [['days' => [1], 'startTime' => '09:00'], 'clave desconocida «days». Permitidas: daysOfWeek, startTime, endTime'],
    'dos claves desconocidas' => [['display' => 'x', 'className' => 'y'], 'claves desconocidas «display», «className»'],
    'día fuera de rango' => [['daysOfWeek' => [1, 7]], 'el día «7» de `daysOfWeek` no es válido'],
    'día como texto' => [['daysOfWeek' => ['1']], 'el día «1» de `daysOfWeek` no es válido'],
    'días vacíos' => [['daysOfWeek' => []], '`daysOfWeek` tiene que ser una lista no vacía'],
    'días que no son lista' => [['daysOfWeek' => 1], '`daysOfWeek` tiene que ser una lista no vacía'],
    'hora mal escrita' => [['startTime' => '9am'], '`startTime` no es una hora válida (llegó «9am»)'],
    'hora no es texto' => [['endTime' => 17], '`endTime` no es una hora válida (llegó int)'],
    'minutos imposibles' => [['startTime' => '09:75'], '`startTime` no es una hora válida'],
    'inicio a las 24:00' => [['startTime' => '24:00', 'endTime' => '30:00'], '`startTime` (24:00) tiene que ser menor que 24:00'],
    'fin pasadas las 48:00' => [['endTime' => '49:00'], '`endTime` (49:00) tiene que ser como mucho 48:00'],
    'fin igual al inicio' => [['startTime' => '09:00', 'endTime' => '09:00'], 'endTime (09:00) tiene que ser posterior a startTime (09:00)'],
    'fin anterior al inicio: pista del turno de noche' => [['startTime' => '22:00', 'endTime' => '06:00'], 'Un turno que cruza la medianoche acaba pasadas las 24:00, por ejemplo 26:00'],
    'un elemento de la lista que no es un tramo' => [[['daysOfWeek' => [1]], 'lunes'], 'Horario laboral, tramo 2: se esperaba un array'],
    'un tramo de la lista sin días' => [[['startTime' => '09:00']], 'Horario laboral, tramo 1: falta `daysOfWeek`'],
]);

// ── Intervalos ─────────────────────────────────────────────────────────

it('de lunes a viernes: un tramo por día laborable y nada el fin de semana', function () {
    $intervals = (new BusinessHours(true))->intervals(bhRange('2026-10-12', '2026-10-19'));

    expect(bhShow($intervals))->toBe([
        '2026-10-12 09:00:00 → 2026-10-12 17:00:00',
        '2026-10-13 09:00:00 → 2026-10-13 17:00:00',
        '2026-10-14 09:00:00 → 2026-10-14 17:00:00',
        '2026-10-15 09:00:00 → 2026-10-15 17:00:00',
        '2026-10-16 09:00:00 → 2026-10-16 17:00:00',
    ]);
});

it('recorta los tramos al rango que se pide', function () {
    $intervals = (new BusinessHours(true))->intervals(bhRange('2026-10-14 12:00', '2026-10-14 20:00'));

    expect(bhShow($intervals))->toBe(['2026-10-14 12:00:00 → 2026-10-14 17:00:00']);
});

it('varios tramos del mismo día siguen separados por el hueco', function () {
    $hours = new BusinessHours([
        ['daysOfWeek' => [1], 'startTime' => '09:00', 'endTime' => '13:00'],
        ['daysOfWeek' => [1], 'startTime' => '15:00', 'endTime' => '19:00'],
    ]);

    expect(bhShow($hours->intervals(bhRange('2026-10-12', '2026-10-13'))))->toBe([
        '2026-10-12 09:00:00 → 2026-10-12 13:00:00',
        '2026-10-12 15:00:00 → 2026-10-12 19:00:00',
    ]);
});

it('los tramos contiguos o solapados se funden en uno', function () {
    $hours = new BusinessHours([
        ['daysOfWeek' => [1], 'startTime' => '13:00', 'endTime' => '17:00'],
        ['daysOfWeek' => [1], 'startTime' => '09:00', 'endTime' => '13:00'],   // desordenados a propósito
        ['daysOfWeek' => [1], 'startTime' => '16:00', 'endTime' => '18:00'],   // solapa con el anterior
    ]);

    expect(bhShow($hours->intervals(bhRange('2026-10-12', '2026-10-13'))))->toBe(['2026-10-12 09:00:00 → 2026-10-12 18:00:00']);
});

it('un turno de noche cruza la medianoche y cuenta para el día siguiente', function () {
    $noche = new BusinessHours(['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']);   // viernes 22:00 → sábado 02:00

    expect(bhShow($noche->intervals(bhRange('2026-10-16', '2026-10-19'))))->toBe(['2026-10-16 22:00:00 → 2026-10-17 02:00:00'])
        // Un rango que EMPIEZA el sábado ve la cola del turno de ayer.
        ->and(bhShow($noche->intervals(bhRange('2026-10-17', '2026-10-17 12:00'))))->toBe(['2026-10-17 00:00:00 → 2026-10-17 02:00:00'])
        // Y uno que lo corta por la mitad, el trozo que cae dentro.
        ->and(bhShow($noche->intervals(bhRange('2026-10-16 23:00', '2026-10-17 01:00'))))->toBe(['2026-10-16 23:00:00 → 2026-10-17 01:00:00']);
});

it('un tramo de hasta 48:00 llega hasta el final del día siguiente', function () {
    $hours = new BusinessHours(['daysOfWeek' => [1], 'startTime' => '20:00', 'endTime' => '48:00']);

    expect(bhShow($hours->intervals(bhRange('2026-10-12', '2026-10-15'))))->toBe(['2026-10-12 20:00:00 → 2026-10-14 00:00:00']);
});

it('sin horario definido, el rango entero es laborable y no hay nada fuera', function () {
    $hours = new BusinessHours(false);
    $range = bhRange('2026-10-14', '2026-10-15');

    expect(bhShow($hours->intervals($range)))->toBe(['2026-10-14 00:00:00 → 2026-10-15 00:00:00'])
        ->and($hours->outside($range))->toBe([])
        ->and($hours->covers($range))->toBeTrue()
        ->and($hours->coversDays($range))->toBeTrue()
        ->and($hours->worksOn(Marker::parse('2026-10-17')))->toBeTrue();
});

it('un rango vacío no tiene intervalos', function () {
    expect((new BusinessHours(true))->intervals(bhRange('2026-10-14 10:00', '2026-10-14 10:00')))->toBe([]);
});

it('outside() es el complemento: lo que se pinta como no laborable', function () {
    $fuera = (new BusinessHours(true))->outside(bhRange('2026-10-14', '2026-10-15'));

    expect(bhShow($fuera))->toBe([
        '2026-10-14 00:00:00 → 2026-10-14 09:00:00',
        '2026-10-14 17:00:00 → 2026-10-15 00:00:00',
    ])->and(bhShow((new BusinessHours(true))->outside(bhRange('2026-10-17', '2026-10-18'))))->toBe(['2026-10-17 00:00:00 → 2026-10-18 00:00:00']);
});

it('outside() une la noche y el fin de semana en un solo tramo si no los separa nada', function () {
    $fuera = (new BusinessHours(true))->outside(bhRange('2026-10-16', '2026-10-19'));

    // Viernes 17:00 hasta el lunes 00:00: la noche y el fin de semana, sin corte a medianoche.
    expect(bhShow($fuera))->toBe([
        '2026-10-16 00:00:00 → 2026-10-16 09:00:00',
        '2026-10-16 17:00:00 → 2026-10-19 00:00:00',
    ]);
});

// ── covers() ───────────────────────────────────────────────────────────

it('covers() es semiabierto: el borde exacto cabe, un minuto de más no', function (string $desde, string $hasta, bool $cubierto) {
    expect((new BusinessHours(true))->covers(bhRange($desde, $hasta)))->toBe($cubierto);
})->with([
    'el tramo entero' => ['2026-10-14 09:00', '2026-10-14 17:00', true],
    'el último minuto' => ['2026-10-14 16:59', '2026-10-14 17:00', true],
    'el primer minuto' => ['2026-10-14 09:00', '2026-10-14 09:01', true],
    'un minuto pasado el cierre' => ['2026-10-14 16:59', '2026-10-14 17:01', false],
    'un minuto antes de abrir' => ['2026-10-14 08:59', '2026-10-14 09:30', false],
    'empieza justo al cerrar' => ['2026-10-14 17:00', '2026-10-14 18:00', false],
    'acaba justo al abrir' => ['2026-10-14 08:00', '2026-10-14 09:00', false],
    'en sábado' => ['2026-10-17 10:00', '2026-10-17 11:00', false],
    'cruza dos días laborables (la noche no lo es)' => ['2026-10-14 16:00', '2026-10-15 10:00', false],
]);

it('covers() cubre un hueco entre dos tramos que se tocan, no uno que los separa', function () {
    $contiguos = new BusinessHours([
        ['daysOfWeek' => [3], 'startTime' => '09:00', 'endTime' => '13:00'],
        ['daysOfWeek' => [3], 'startTime' => '13:00', 'endTime' => '17:00'],
    ]);
    $conComida = new BusinessHours([
        ['daysOfWeek' => [3], 'startTime' => '09:00', 'endTime' => '13:00'],
        ['daysOfWeek' => [3], 'startTime' => '14:00', 'endTime' => '17:00'],
    ]);
    $span = bhRange('2026-10-14 11:00', '2026-10-14 15:00');

    expect($contiguos->covers($span))->toBeTrue()
        ->and($conComida->covers($span))->toBeFalse();
});

it('covers() de un turno de noche: cabe de un lado a otro de la medianoche', function () {
    $noche = new BusinessHours(['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']);

    expect($noche->covers(bhRange('2026-10-16 23:00', '2026-10-17 01:00')))->toBeTrue()
        ->and($noche->covers(bhRange('2026-10-17 01:00', '2026-10-17 03:00')))->toBeFalse()
        ->and($noche->covers(bhRange('2026-10-17 01:00', '2026-10-17 02:00')))->toBeTrue();
});

it('covers() no acepta un rango vacío ni uno de años (no es un evento, y recorrerlo costaría)', function () {
    $hours = new BusinessHours(['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6], 'startTime' => '00:00', 'endTime' => '24:00']);

    expect($hours->covers(bhRange('2026-10-14 10:00', '2026-10-14 10:00')))->toBeFalse()
        ->and($hours->covers(bhRange('2026-10-14', '2026-10-20')))->toBeTrue()
        ->and($hours->covers(bhRange('0001-01-01', '9999-01-01')))->toBeFalse()
        ->and($hours->coversDays(bhRange('0001-01-01', '9999-01-01')))->toBeFalse();
});

// ── Días ───────────────────────────────────────────────────────────────

it('worksOn() y coversDays(): un día con algún tramo es laborable, un evento de día completo no «cabe» en un horario', function () {
    $hours = new BusinessHours(true);

    expect($hours->worksOn(Marker::parse('2026-10-16 15:00')))->toBeTrue()
        ->and($hours->worksOn(Marker::parse('2026-10-17')))->toBeFalse()
        ->and($hours->coversDays(bhRange('2026-10-15', '2026-10-17')))->toBeTrue()
        ->and($hours->coversDays(bhRange('2026-10-16', '2026-10-18')))->toBeFalse()
        // Un día completo [14, 15) no cabe entero en 09-17, y aun así es un día en que se trabaja.
        ->and($hours->covers(bhRange('2026-10-14', '2026-10-15')))->toBeFalse()
        ->and($hours->coversDays(bhRange('2026-10-14', '2026-10-15')))->toBeTrue();
});

it('el día siguiente a un turno de noche también trabaja, de madrugada', function () {
    $noche = new BusinessHours(['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']);

    expect($noche->worksOn(Marker::parse('2026-10-16')))->toBeTrue()   // viernes
        ->and($noche->worksOn(Marker::parse('2026-10-17')))->toBeTrue()   // sábado, hasta las 02:00
        ->and($noche->worksOn(Marker::parse('2026-10-18')))->toBeFalse()
        ->and($noche->worksOn(Marker::parse('2026-10-15')))->toBeFalse();
});

// ── Horas de pared y cambio de horario (Europe/Madrid) ─────────────────

it('el cambio de horario no mueve el horario laboral: las 09:00 siguen siendo las 09:00 de pared', function (string $dia, string $offsetDe0900, string $offsetDe1700) {
    $clock = new WallClock('Europe/Madrid');
    $hours = new BusinessHours(['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6]]);
    [$tramo] = $hours->intervals(bhRange($dia, date('Y-m-d', strtotime("{$dia} +1 day"))));

    expect($tramo->start->toDateTimeString())->toBe("{$dia} 09:00:00")
        ->and($tramo->end->toDateTimeString())->toBe("{$dia} 17:00:00")
        // Los instantes reales cambian de offset; el horario de pared no.
        ->and($clock->toInstant($tramo->start)->format('c'))->toBe("{$dia}T09:00:00{$offsetDe0900}")
        ->and($clock->toInstant($tramo->end)->format('c'))->toBe("{$dia}T17:00:00{$offsetDe1700}");
})->with([
    'el día antes del cambio de otoño (+02:00)' => ['2026-10-24', '+02:00', '+02:00'],
    'el día del cambio de otoño, que mide 25 h (+01:00)' => ['2026-10-25', '+01:00', '+01:00'],
    'el día del cambio de primavera, que mide 23 h (+02:00)' => ['2026-03-29', '+02:00', '+02:00'],
    'el día antes del cambio de primavera (+01:00)' => ['2026-03-28', '+01:00', '+01:00'],
]);

it('un evento con offset el día del cambio de horario se compara por su hora de pared', function () {
    $hours = new BusinessHours(['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6]]);
    $clock = new WallClock('Europe/Madrid');

    // 09:00+01:00 del 25-oct es la hora de pared 09:00; 08:30+01:00 es la 08:30.
    $nueve = $clock->toMarker('2026-10-25T09:00:00+01:00');
    $ochoYMedia = $clock->toMarker('2026-10-25T08:30:00+01:00');

    expect($hours->covers(new DateRange($nueve, $nueve->addMinutes(60))))->toBeTrue()
        ->and($hours->covers(new DateRange($ochoYMedia, $ochoYMedia->addMinutes(60))))->toBeFalse();
});

// ── Recursos ───────────────────────────────────────────────────────────

it('el horario de un recurso gana al del calendario; sin él, el del calendario', function () {
    $calendario = new BusinessHours(true);
    $propio = CalendarResource::make('a', 'Sala A', businessHours: ['startTime' => '08:00', 'endTime' => '12:00']);
    $sinHorario = CalendarResource::make('b', 'Sala B');

    expect(BusinessHours::forResource($propio, $calendario)->toArray()[0]['endTime'])->toBe('12:00')
        ->and(BusinessHours::forResource($sinHorario, $calendario))->toBe($calendario)
        ->and(BusinessHours::forResource(null, $calendario))->toBe($calendario);
});

it('un recurso con businessHours false o [] está siempre abierto aunque el calendario tenga horario', function (bool|array $valor) {
    $maquina = CalendarResource::make('m', 'Máquina', businessHours: $valor);

    expect(BusinessHours::forResource($maquina, new BusinessHours(true))->isEmpty())->toBeTrue();
})->with(['false' => [false], 'lista vacía' => [[]]]);

it('un horario de recurso mal escrito falla con el id del recurso en el mensaje', function () {
    $mal = CalendarResource::make('sala-9', 'Sala 9', businessHours: ['days' => [1, 2, 3]]);

    expect(fn () => BusinessHours::forResource($mal, new BusinessHours(true)))
        ->toThrow(InvalidArgumentException::class, 'Recurso «sala-9»: Horario laboral: clave desconocida «days»');
});

it('encode() deja los tramos como los lee el cliente', function () {
    $hours = new BusinessHours([
        ['daysOfWeek' => [1], 'startTime' => '09:00', 'endTime' => '13:00'],
        ['daysOfWeek' => [1], 'startTime' => '15:00', 'endTime' => '19:00'],
    ]);
    $tramos = $hours->intervals(bhRange('2026-10-12', '2026-10-13'));
    $lunes = Marker::parse('2026-10-12')->ts;

    expect(BusinessHours::encode($tramos))->toBe(($lunes + 9 * 3600).':'.($lunes + 13 * 3600).','.($lunes + 15 * 3600).':'.($lunes + 19 * 3600))
        ->and(BusinessHours::encode([]))->toBe('');
});
