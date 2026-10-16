<?php

use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WeekRules;

it('numera las semanas como FullCalendar', function () {
    // headless-calendar: 2013-11-17 (domingo) es la 47 en 'en' y la 46 en ISO.
    $sunday = Marker::parse('2013-11-17');

    expect(WeekRules::forLocale('en')->weekNumber($sunday))->toBe(47)
        ->and(WeekRules::iso()->weekNumber($sunday))->toBe(46)
        ->and(WeekRules::forLocale('en')->weekNumber(Marker::parse('2013-11-23')))->toBe(47);
});

it('las semanas ISO de los bordes de año', function (string $date, int $week) {
    expect(WeekRules::iso()->weekNumber(Marker::parse($date)))->toBe($week);
})->with([
    '1-ene-2021 es la 53 de 2020' => ['2021-01-01', 53],
    '1-ene-2026 (jueves) es la 1' => ['2026-01-01', 1],
    '30-dic-2024 es la 1 de 2025' => ['2024-12-30', 1],
    '28-dic-2026 es la 53' => ['2026-12-28', 53],
    'mitad de año' => ['2026-10-16', 42],
]);

it('cuenta semanas por año', function () {
    expect(WeekRules::iso()->weeksInYear(2026))->toBe(53)
        ->and(WeekRules::iso()->weeksInYear(2025))->toBe(52);
});

it('firstDay cambia solo el primer día, no la numeración', function () {
    $rules = WeekRules::resolve(2, 'iso', 'es');

    expect($rules->dow)->toBe(2)
        ->and($rules->doy)->toBe(4)
        ->and($rules->startOfWeek(Marker::parse('2026-10-16'))->toDateString())->toBe('2026-10-13');
});

it('usa las reglas del locale', function () {
    expect(WeekRules::forLocale('es')->dow)->toBe(1)
        ->and(WeekRules::forLocale('en-US')->dow)->toBe(0)
        ->and(WeekRules::resolve(null, 'locale', 'en')->dow)->toBe(0)
        ->and(WeekRules::resolve(null, 'iso', 'en')->dow)->toBe(1);
});

it('rechaza un primer día fuera de rango', function () {
    new WeekRules(7);
})->throws(InvalidArgumentException::class);

it('la numeración ISO coincide con la de PHP en cada día de 2000 a 2040', function () {
    // Paridad contra una referencia externa, como Charts hace con d3: los casos
    // elegidos a mano no cubren todos los bordes de año.
    $rules = WeekRules::iso();
    $distintos = [];

    for ($d = Marker::parse('2000-01-01'); $d->year() <= 2040; $d = $d->addDays(1)) {
        if ($rules->weekNumber($d) !== (int) gmdate('W', $d->ts)) {
            $distintos[] = $d->toDateString();
        }
    }

    expect($distintos)->toBe([]);
});
