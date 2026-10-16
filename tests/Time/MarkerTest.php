<?php

use KoreUi\Calendar\Time\Marker;

it('un día mide siempre 86 400 segundos, también en un cambio de horario', function () {
    // En Madrid el 29-mar-2026 tiene 23 h. En el espacio de markers no existe.
    $d = Marker::parse('2026-03-29');

    expect($d->addDays(1)->ts - $d->ts)->toBe(86_400)
        ->and((string) $d->addDays(1))->toBe('2026-03-30 00:00:00');
});

it('lee y escribe los campos de pared', function () {
    $m = Marker::parse('2026-10-16 10:45:30');

    expect([$m->year(), $m->month(), $m->day(), $m->hour(), $m->minute(), $m->second()])
        ->toBe([2026, 10, 16, 10, 45, 30])
        ->and($m->dayOfWeek())->toBe(5)
        ->and($m->secondsOfDay())->toBe(10 * 3600 + 45 * 60 + 30)
        ->and(Marker::parse('2026-10-16T10:45')->equals(Marker::parse('2026-10-16 10:45:00')))->toBeTrue();
});

it('rechaza un string con zona: una hora de pared no tiene', function () {
    Marker::parse('2026-10-16T10:00:00+02:00');
})->throws(InvalidArgumentException::class, 'sin zona');

it('funciona con fechas anteriores a 1970', function () {
    $m = Marker::parse('1969-12-31 23:15');

    expect($m->hour())->toBe(23)
        ->and($m->minute())->toBe(15)
        ->and((string) $m->startOfDay())->toBe('1969-12-31 00:00:00');
});

it('suma meses recortando al último día, sin saltar de mes', function (string $from, int $months, string $expected) {
    expect(Marker::parse($from)->addMonths($months)->toDateString())->toBe($expected);
})->with([
    '31-ene + 1 = 28-feb' => ['2026-01-31', 1, '2026-02-28'],
    'bisiesto' => ['2028-01-31', 1, '2028-02-29'],
    'hacia atrás' => ['2026-03-31', -1, '2026-02-28'],
    'cruza año' => ['2026-12-15', 1, '2027-01-15'],
    'atrás cruza año' => ['2026-01-15', -13, '2024-12-15'],
]);

it('calcula el inicio de semana para cualquier primer día', function (int $firstDay, string $expected) {
    // 18-oct-2026 es domingo.
    expect(Marker::parse('2026-10-18 15:00')->startOfWeek($firstDay)->toDateString())->toBe($expected);
})->with([
    'lunes' => [1, '2026-10-12'],
    'domingo' => [0, '2026-10-18'],
    'sábado' => [6, '2026-10-17'],
    'martes' => [2, '2026-10-13'],
]);

it('cuenta días completos entre inicios de día', function () {
    expect(Marker::parse('2026-10-16 23:59')->diffInDays(Marker::parse('2026-10-17 00:01')))->toBe(1)
        ->and(Marker::parse('2026-10-17')->diffInDays(Marker::parse('2026-10-16')))->toBe(-1);
});

it('se formatea con Carbon conservando los campos de pared', function () {
    $c = Marker::parse('2026-10-16 10:00')->toCarbon()->locale('es');

    expect($c->translatedFormat('l j \d\e F H:i'))->toBe('viernes 16 de octubre 10:00');
});
